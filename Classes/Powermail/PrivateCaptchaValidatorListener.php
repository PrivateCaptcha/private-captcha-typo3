<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Powermail;

use In2code\Powermail\Domain\Model\Answer;
use In2code\Powermail\Domain\Model\Field;
use In2code\Powermail\Domain\Model\Form;
use In2code\Powermail\Domain\Model\Mail;
use In2code\Powermail\Domain\Service\ConfigurationService;
use In2code\Powermail\Events\CustomValidatorEvent;
use In2code\Powermail\Events\FormControllerFormActionEvent;
use In2code\Powermail\Utility\ConfigurationUtility;
use In2code\Powermail\Utility\HashUtility;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\Service\SolutionVault;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy;
use TYPO3\CMS\Extbase\Security\HashScope;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * @internal
 */
final class PrivateCaptchaValidatorListener
{
    private const FLOW_DIRECT = 'direct';

    private const FLOW_CONFIRMATION = 'confirmation';

    private const FLOW_CONFIRMATION_FINAL = 'confirmation-final';

    /**
     * @var \WeakMap<Mail, array<int, array{
     *     answer: Answer,
     *     formUid: int,
     *     fieldUid: int,
     *     marker: string,
     *     sitekey: string,
     *     contentUid: int
     * }>>
     */
    private \WeakMap $confirmationAnswers;

    public function __construct(
        private readonly ConfigurationResolver $configurationResolver,
        private readonly CaptchaVerifierInterface $captchaVerifier,
        private readonly SolutionVault $solutionVault,
        private readonly HashService $hashService,
        private readonly ConfirmationProofStore $confirmationProofStore,
        private readonly ConfigurationService $powermailConfigurationService,
        private readonly FlexFormService $flexFormService,
    ) {
        $this->confirmationAnswers = new \WeakMap();
    }

    public function __invoke(CustomValidatorEvent $event): void
    {
        $mail = $event->getMail();
        $form = $this->form($mail);
        if (!$form instanceof Form) {
            return;
        }
        $fields = $this->privateCaptchaFields($form);
        if ($fields === []) {
            return;
        }

        /** @var array<int, array{solution: mixed, answer: Answer}|null> $submissions */
        $submissions = [];
        $formUid = $form->getUid() ?? 0;
        foreach ($fields as $field) {
            $answers = $this->answers($mail, $field);
            $submission = null;
            foreach ($answers as $answer) {
                $nonce = $answer->getValue();
                $candidate = is_string($nonce)
                    ? $this->consumeSolution($nonce, $formUid, $field->getMarker())
                    : null;
                if ($candidate !== null && $submission === null) {
                    $submission = $candidate;
                }
                $this->removeAnswer($mail, $answer);
            }
            $submissions[$field->getUid() ?? 0] = count($answers) === 1 && $submission !== null
                ? ['solution' => $submission['solution'], 'answer' => $answers[0]]
                : null;
        }

        $validator = $event->getCustomValidator();
        $request = $validator->getRequest();
        $site = $request?->getAttribute('site');
        if (!$request instanceof ServerRequestInterface || !$site instanceof Site) {
            $this->addErrors($event, $fields);
            return;
        }
        if (($mail->getUid() ?? 0) > 0) {
            if ($this->isAuthenticatedOptinCompletion($request, $mail)) {
                return;
            }
            $this->addErrors($event, $fields);
            return;
        }
        try {
            $configuration = $this->configurationResolver->resolveSite($site);
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addErrors($event, $fields);
            return;
        }
        if (!$configuration->requestedIntegrations->powermail) {
            return;
        }
        $flow = $this->submissionFlow($request);
        if (!$configuration->integrations->powermail
            || $flow === null
            || !$this->flowMatchesConfiguration($request, $form, $flow)
        ) {
            $this->addErrors($event, $fields);
            return;
        }

        if ($flow === self::FLOW_CONFIRMATION_FINAL) {
            try {
                $businessDigest = $this->businessDigest($mail);
            } catch (\Throwable) {
                $this->addErrors($event, $fields);
                return;
            }
            foreach ($fields as $field) {
                $submission = $submissions[$field->getUid() ?? 0] ?? null;
                $accepted = is_array($submission)
                    && is_string($submission['solution'])
                    && $this->confirmationProofStore->consume(
                        $request,
                        $site,
                        $submission['solution'],
                        $formUid,
                        $field->getUid() ?? 0,
                        $field->getMarker(),
                        $configuration->sitekey,
                        $this->contentUid($request),
                        $businessDigest,
                    );
                if (!$accepted) {
                    $this->addError($event, $field);
                }
            }
            return;
        }

        $acceptedSubmissions = [];
        foreach ($fields as $field) {
            $submission = $submissions[$field->getUid() ?? 0] ?? null;
            if (!is_array($submission)) {
                $this->addError($event, $field);
                continue;
            }
            try {
                $result = $this->captchaVerifier->verify($submission['solution'], $configuration);
            } catch (\Throwable) {
                $this->addError($event, $field);
                continue;
            }
            if (!$result->accepted) {
                $this->addError($event, $field);
                continue;
            }
            $acceptedSubmissions[$field->getUid() ?? 0] = $submission;
        }
        if ($flow !== self::FLOW_CONFIRMATION || count($acceptedSubmissions) !== count($fields)) {
            return;
        }

        $confirmationAnswers = [];
        $contentUid = $this->contentUid($request);
        foreach ($fields as $field) {
            $fieldUid = $field->getUid() ?? 0;
            $confirmationAnswers[$fieldUid] = [
                'answer' => $acceptedSubmissions[$fieldUid]['answer'],
                'formUid' => $formUid,
                'fieldUid' => $fieldUid,
                'marker' => $field->getMarker(),
                'sitekey' => $configuration->sitekey,
                'contentUid' => $contentUid,
            ];
        }
        $this->confirmationAnswers[$mail] = $confirmationAnswers;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function issueConfirmationProofs(
        Mail $mail,
        ServerRequestInterface $request,
        array $settings,
    ): void {
        $confirmationAnswers = $this->confirmationAnswers[$mail] ?? [];
        unset($this->confirmationAnswers[$mail]);
        $form = $this->form($mail);
        $site = $request->getAttribute('site');
        $mainSettings = $settings['main'] ?? null;
        if (!$form instanceof Form) {
            if ($confirmationAnswers !== []) {
                throw new \RuntimeException('Powermail confirmation proof could not be issued.');
            }
            return;
        }
        $fields = $this->privateCaptchaFields($form);
        if ($fields === []) {
            if ($confirmationAnswers !== []) {
                throw new \RuntimeException('Powermail confirmation proof could not be issued.');
            }
            return;
        }
        if (!$site instanceof Site
            || !is_array($mainSettings)
            || empty($mainSettings['confirmation'])
            || !$this->formIsConfigured($form, $settings)
        ) {
            throw new \RuntimeException('Powermail confirmation proof could not be issued.');
        }
        try {
            $configuration = $this->configurationResolver->resolveSite($site);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            throw new \RuntimeException('Powermail confirmation proof could not be issued.', 0, $exception);
        }
        if (!$configuration->requestedIntegrations->powermail) {
            $this->removePrivateCaptchaAnswers($mail, $fields);
            return;
        }
        if (!$configuration->integrations->powermail) {
            throw new \RuntimeException('Powermail confirmation proof could not be issued.');
        }
        if (count($confirmationAnswers) !== count($fields)) {
            throw new \RuntimeException('Powermail confirmation proof could not be issued.');
        }

        $issuedProofs = [];
        try {
            $businessDigest = $this->businessDigest($mail);
            foreach ($fields as $field) {
                $fieldUid = $field->getUid() ?? 0;
                $verifiedSubmission = $confirmationAnswers[$fieldUid] ?? null;
                if ($verifiedSubmission === null || !$this->matchesConfirmationBinding(
                    $verifiedSubmission,
                    $form->getUid() ?? 0,
                    $fieldUid,
                    $field->getMarker(),
                    $configuration->sitekey,
                    $this->contentUid($request),
                )) {
                    throw new \RuntimeException('Powermail confirmation proof could not be issued.');
                }
                $proof = $this->confirmationProofStore->issue(
                    $request,
                    $site,
                    $form->getUid() ?? 0,
                    $fieldUid,
                    $field->getMarker(),
                    $configuration->sitekey,
                    $this->contentUid($request),
                    $businessDigest,
                );
                if (!is_string($proof)) {
                    throw new \RuntimeException('Powermail confirmation proof could not be issued.');
                }
                $issuedProofs[] = $proof;
                $answer = $verifiedSubmission['answer'];
                $answer->setValue($proof);
                $mail->addAnswer($answer);
            }
        } catch (\Throwable $exception) {
            foreach ($issuedProofs as $proof) {
                $this->confirmationProofStore->revoke($proof);
            }
            $this->removePrivateCaptchaAnswers($mail, $fields);
            throw new \RuntimeException('Powermail confirmation proof could not be issued.', 0, $exception);
        }
    }

    public function revokeConfirmationProofsOnBack(FormControllerFormActionEvent $event): void
    {
        $form = $event->getForm();
        $request = $event->getRequest();
        $site = $request?->getAttribute('site');
        $settings = $event->getFormController()->getSettings();
        $mainSettings = $settings['main'] ?? null;
        if (!$form instanceof Form
            || !$request instanceof ServerRequestInterface
            || !$site instanceof Site
            || strtoupper($request->getMethod()) !== 'POST'
            || !is_array($mainSettings)
            || empty($mainSettings['confirmation'])
            || !$this->formIsConfigured($form, $settings)
            || !$this->isBackSubmission($request)
        ) {
            return;
        }
        try {
            $configuration = $this->configurationResolver->resolveSite($site);
        } catch (\InvalidArgumentException|\LogicException) {
            return;
        }
        $arguments = $request->getParsedBody();
        $pluginArguments = is_array($arguments) ? ($arguments['tx_powermail_pi1'] ?? null) : null;
        $submittedFields = is_array($pluginArguments) ? ($pluginArguments['field'] ?? null) : null;
        if (!is_array($submittedFields)) {
            return;
        }
        foreach ($this->privateCaptchaFields($form) as $field) {
            $transportNonce = $submittedFields[$field->getMarker()] ?? null;
            $submission = is_string($transportNonce)
                ? $this->consumeSolution(
                    $transportNonce,
                    $form->getUid() ?? 0,
                    $field->getMarker(),
                )
                : null;
            if (is_string($submission['solution'] ?? null)) {
                $this->confirmationProofStore->consumeOnBack(
                    $request,
                    $site,
                    $submission['solution'],
                    $form->getUid() ?? 0,
                    $field->getUid() ?? 0,
                    $field->getMarker(),
                    $configuration->sitekey,
                    $this->contentUid($request),
                );
            }
        }
    }

    private function submissionFlow(ServerRequestInterface $request): ?string
    {
        $actions = $this->submissionActions($request);
        if ($actions === null) {
            return null;
        }

        return match ([$actions['current'], $actions['referrer']]) {
            ['create', 'form'] => self::FLOW_DIRECT,
            ['confirmation', 'form'] => self::FLOW_CONFIRMATION,
            ['create', 'confirmation'] => self::FLOW_CONFIRMATION_FINAL,
            default => null,
        };
    }

    /**
     * @return array{solution: mixed}|null
     */
    private function consumeSolution(string $nonce, int $formUid, string $marker): ?array
    {
        $submission = $this->solutionVault->consume($nonce);
        if ($submission === null || $submission['context'] !== [
            'formUid' => $formUid,
            'marker' => $marker,
        ]) {
            return null;
        }

        return ['solution' => $submission['solution']];
    }

    private function isBackSubmission(ServerRequestInterface $request): bool
    {
        $actions = $this->submissionActions($request);

        return $actions === ['current' => 'form', 'referrer' => 'confirmation'];
    }

    /**
     * @return array{current: string, referrer: string}|null
     */
    private function submissionActions(ServerRequestInterface $request): ?array
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return null;
        }
        $parameters = $request->getAttribute('extbase');
        if (!$parameters instanceof ExtbaseRequestParameters
            || $parameters->getControllerExtensionName() !== 'Powermail'
            || $parameters->getControllerName() !== 'Form'
        ) {
            return null;
        }
        $referrer = $parameters->getInternalArgument('__referrer');
        $signedRequest = is_array($referrer) ? ($referrer['@request'] ?? null) : null;
        if (!is_string($signedRequest) || $signedRequest === '') {
            return null;
        }
        try {
            $referrerRequest = json_decode(
                $this->hashService->validateAndStripHmac(
                    $signedRequest,
                    HashScope::ReferringRequest->prefix(),
                ),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($referrerRequest)) {
            return null;
        }
        $referrerAction = $referrerRequest['@action'] ?? null;
        if (($referrerRequest['@extension'] ?? null) !== 'Powermail'
            || ($referrerRequest['@controller'] ?? null) !== 'Form'
            || !is_string($referrerAction)
        ) {
            return null;
        }

        return [
            'current' => $parameters->getControllerActionName(),
            'referrer' => $referrerAction,
        ];
    }

    private function flowMatchesConfiguration(
        ServerRequestInterface $request,
        Form $form,
        string $flow,
    ): bool {
        try {
            $settings = $this->powermailConfigurationService->getTypoScriptSettings();
            $contentObject = $request->getAttribute('currentContentObject');
            if ($contentObject instanceof ContentObjectRenderer) {
                $flexForm = $this->flexFormService->convertFlexFormContentToArray(
                    is_string($contentObject->data['pi_flexform'] ?? null)
                        ? $contentObject->data['pi_flexform']
                        : '',
                );
                $flexFormRoot = $flexForm['settings'] ?? null;
                $flexFormSettings = is_array($flexFormRoot) ? ($flexFormRoot['flexform'] ?? null) : null;
                if (is_array($flexFormSettings)) {
                    $settings = ConfigurationUtility::mergeTypoScript2FlexForm([
                        'setup' => $settings,
                        'flexform' => $flexFormSettings,
                    ]);
                }
            }
        } catch (\Throwable) {
            return false;
        }
        $mainSettings = $settings['main'] ?? null;
        if (!is_array($mainSettings)) {
            return false;
        }
        if (!$this->formIsConfigured($form, $settings)) {
            return false;
        }
        $confirmationEnabled = !empty($mainSettings['confirmation']);

        return $confirmationEnabled
            ? in_array($flow, [self::FLOW_CONFIRMATION, self::FLOW_CONFIRMATION_FINAL], true)
            : $flow === self::FLOW_DIRECT;
    }

    private function form(Mail $mail): ?Form
    {
        $form = $mail->getForm();
        if ($form instanceof LazyLoadingProxy) {
            $form = $form->_loadRealInstance();
        }

        return $form instanceof Form ? $form : null;
    }

    /**
     * @return list<Field>
     */
    private function privateCaptchaFields(Form $form): array
    {
        return array_values(array_filter(
            $form->getFields(),
            static fn(Field $field): bool => $field->getType() === 'privateCaptcha',
        ));
    }

    private function businessDigest(Mail $mail): string
    {
        $businessAnswers = [];
        foreach ($mail->getAnswers() as $answer) {
            $field = $answer->getField();
            $fieldUid = $field?->getUid() ?? 0;
            if (!$field instanceof Field || $fieldUid < 1 || $field->getMarker() === '') {
                throw new \RuntimeException('Powermail answer binding is invalid.');
            }
            $businessAnswers[] = [
                'fieldUid' => $fieldUid,
                'marker' => $field->getMarker(),
                'valueType' => $answer->getValueType(),
                'value' => serialize($answer->getRawValue()),
            ];
        }
        usort($businessAnswers, static fn(array $left, array $right): int => [
            $left['fieldUid'],
            $left['marker'],
            $left['valueType'],
            $left['value'],
        ] <=> [
            $right['fieldUid'],
            $right['marker'],
            $right['valueType'],
            $right['value'],
        ]);

        return hash('sha256', serialize($businessAnswers));
    }

    /**
     * @return list<Answer>
     */
    private function answers(Mail $mail, Field $field): array
    {
        $answers = [];
        foreach ($mail->getAnswers() as $answer) {
            if ($answer->getField()?->getUid() === $field->getUid()) {
                $answers[] = $answer;
            }
        }

        return $answers;
    }

    private function removeAnswer(Mail $mail, Answer $answer): void
    {
        $answer->setValue('');
        $answer->setOriginalValue('');
        $mail->removeAnswer($answer);
    }

    /**
     * @param list<Field> $fields
     */
    private function removePrivateCaptchaAnswers(Mail $mail, array $fields): void
    {
        foreach ($fields as $field) {
            foreach ($this->answers($mail, $field) as $answer) {
                $this->removeAnswer($mail, $answer);
            }
        }
    }

    /**
     * @param array{
     *     answer: Answer,
     *     formUid: int,
     *     fieldUid: int,
     *     marker: string,
     *     sitekey: string,
     *     contentUid: int
     * } $submission
     */
    private function matchesConfirmationBinding(
        array $submission,
        int $formUid,
        int $fieldUid,
        string $marker,
        string $sitekey,
        int $contentUid,
    ): bool {
        return $submission['formUid'] === $formUid
            && $submission['fieldUid'] === $fieldUid
            && hash_equals($submission['marker'], $marker)
            && hash_equals($submission['sitekey'], $sitekey)
            && $submission['contentUid'] === $contentUid;
    }

    private function isAuthenticatedOptinCompletion(ServerRequestInterface $request, Mail $mail): bool
    {
        $parameters = $request->getAttribute('extbase');
        if (!$parameters instanceof ExtbaseRequestParameters
            || $parameters->getControllerExtensionName() !== 'Powermail'
            || $parameters->getControllerName() !== 'Form'
            || $parameters->getControllerActionName() !== 'create'
        ) {
            return false;
        }
        $hash = $parameters->hasArgument('hash') ? $parameters->getArgument('hash') : null;

        return is_string($hash) && $hash !== '' && HashUtility::isHashValid($hash, $mail);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function formIsConfigured(Form $form, array $settings): bool
    {
        $mainSettings = $settings['main'] ?? null;
        if (!is_array($mainSettings)) {
            return false;
        }
        $configuredFormUids = array_filter(array_map(
            static fn(string $uid): int => ctype_digit(trim($uid)) ? (int)trim($uid) : 0,
            explode(',', is_scalar($mainSettings['form'] ?? null) ? (string)$mainSettings['form'] : ''),
        ));

        return in_array($form->getUid() ?? 0, $configuredFormUids, true);
    }

    private function contentUid(ServerRequestInterface $request): int
    {
        $contentObject = $request->getAttribute('currentContentObject');
        $uid = $contentObject instanceof ContentObjectRenderer ? ($contentObject->data['uid'] ?? null) : null;

        return is_int($uid) || is_string($uid) && ctype_digit($uid) ? max(0, (int)$uid) : 0;
    }

    /**
     * @param list<Field> $fields
     */
    private function addErrors(CustomValidatorEvent $event, array $fields): void
    {
        foreach ($fields as $field) {
            $this->addError($event, $field);
        }
    }

    private function addError(CustomValidatorEvent $event, Field $field): void
    {
        $message = LocalizationUtility::translate(
            'LLL:EXT:private_captcha/Resources/Private/Language/locallang_form.xlf:validation.verificationFailed',
        ) ?? 'The CAPTCHA could not be verified. Please try again.';
        $event->getCustomValidator()->setErrorAndMessage($field, $message);
    }
}
