<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\Service\SolutionVault;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

/**
 * @internal
 */
final class PrivateCaptchaValidator extends AbstractValidator
{
    protected $acceptsEmptyValues = false;

    /** @var array<string, array{string, string, string}> */
    protected $supportedOptions = [
        'formIdentifier' => ['', 'Form identifier used in the parsed request body.', 'string'],
        'elementIdentifier' => ['', 'Element identifier used in the parsed request body.', 'string'],
        'formPersistenceIdentifier' => ['', 'Persistent form definition identifier.', 'string'],
    ];

    public function __construct(
        private readonly ConfigurationResolver $configurationResolver,
        private readonly CaptchaVerifierInterface $captchaVerifier,
        private readonly FormProofStore $proofStore,
        private readonly SolutionVault $solutionVault,
    ) {}

    protected function isValid(mixed $value): void
    {
        $submission = is_string($value) ? $this->solutionVault->consume($value) : null;
        $request = $this->getRequest();
        $site = $request?->getAttribute('site');
        if ($request === null || !$site instanceof Site || strtoupper($request->getMethod()) !== 'POST') {
            $this->addVerificationError();
            return;
        }

        try {
            $configuration = $this->configurationResolver->resolveSite($site);
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addVerificationError();
            return;
        }
        if (!$configuration->requestedIntegrations->formFramework) {
            return;
        }
        if (!$configuration->integrations->formFramework) {
            $this->addVerificationError();
            return;
        }

        $formIdentifier = $this->options['formIdentifier'] ?? '';
        $elementIdentifier = $this->options['elementIdentifier'] ?? '';
        $formPersistenceIdentifier = $this->options['formPersistenceIdentifier'] ?? '';
        if (!is_string($formIdentifier) || $formIdentifier === ''
            || !is_string($elementIdentifier) || $elementIdentifier === ''
            || !is_string($formPersistenceIdentifier) || $formPersistenceIdentifier === ''
            || $submission === null
            || !$submission['context'] instanceof FormProofBinding
            || !$submission['context']->isFor(
                $formIdentifier,
                $elementIdentifier,
                $formPersistenceIdentifier,
            )
        ) {
            $this->addVerificationError();
            return;
        }

        try {
            $result = $this->captchaVerifier->verify(
                $submission['solution'],
                $configuration->withSitekey($submission['context']->sitekey()),
            );
            if ($result->accepted) {
                $this->proofStore->issue($value, $submission['context']);
            }
        } catch (\Throwable) {
            $this->addVerificationError();
            return;
        }

        if (!$result->accepted) {
            $this->addVerificationError();
        }
    }

    private function addVerificationError(): void
    {
        $this->addError(
            $this->translateErrorMessage(
                'LLL:EXT:private_captcha/Resources/Private/Language/locallang_form.xlf:validation.verificationFailed',
            ),
            1774639825,
        );
    }
}
