<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Form\Domain\Model\FormElements\FormElementInterface;
use TYPO3\CMS\Form\Service\TranslationService;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

/**
 * @internal
 */
final class PrivateCaptchaGuardFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly ConfigurationResolver $configurationResolver,
        private readonly FormProofStore $proofStore,
        private readonly FormRenderedSitekeyStore $renderedSitekeyStore,
        private readonly FormRequestSanitizer $requestSanitizer,
        private readonly TranslationService $translationService,
    ) {}

    protected function executeInternal(): ?string
    {
        $formRuntime = $this->finisherContext->getFormRuntime();
        $elements = array_values(array_filter(
            $formRuntime->getFormDefinition()->getRenderablesRecursively(),
            static fn(mixed $renderable): bool => $renderable instanceof FormElementInterface
                && $renderable->getType() === 'PrivateCaptcha',
        ));
        if ($elements === []) {
            return null;
        }
        $sanitizedRequest = $this->requestSanitizer->sanitize(
            $formRuntime,
            $this->finisherContext->getRequest(),
            $elements,
        );
        if (!$sanitizedRequest instanceof Request) {
            $this->clearValues($elements);
            return $this->reject();
        }
        (new \ReflectionProperty($this->finisherContext, 'request'))
            ->setValue($this->finisherContext, $sanitizedRequest);

        $site = $this->finisherContext->getRequest()->getAttribute('site');
        try {
            $configuration = $site instanceof Site
                ? $this->configurationResolver->resolveSite($site)
                : null;
        } catch (\InvalidArgumentException|\LogicException) {
            $configuration = null;
        }
        if ($configuration !== null && !$configuration->requestedIntegrations->formFramework) {
            $this->clearValues($elements);
            return null;
        }
        if ($configuration === null || !$site instanceof Site || !$configuration->integrations->formFramework) {
            $this->clearValues($elements);
            return $this->reject();
        }

        $formSession = $formRuntime->getFormSession();
        if ($formSession === null) {
            $this->clearValues($elements);
            return $this->reject();
        }

        $formState = $formRuntime->getFormState();
        $formIdentifier = $formRuntime->getIdentifier();
        $persistenceIdentifier = $formRuntime->getFormDefinition()->getPersistenceIdentifier();
        foreach ($elements as $element) {
            $elementIdentifier = $element->getIdentifier();
            $nonce = $formState?->getFormValue($elementIdentifier);
            $sitekey = $this->renderedSitekeyStore->recall(
                $formState,
                $site->getIdentifier(),
                $persistenceIdentifier,
                $formIdentifier,
                $elementIdentifier,
            );
            $formState?->setFormValue($elementIdentifier, null);
            $this->renderedSitekeyStore->forget(
                $formState,
                $formIdentifier,
                $elementIdentifier,
            );
            if (!is_string($nonce) || $sitekey === null) {
                return $this->reject();
            }
            $binding = new FormProofBinding(
                $formSession->getIdentifier(),
                $site->getIdentifier(),
                $formIdentifier,
                $elementIdentifier,
                $sitekey,
                $persistenceIdentifier,
            );
            try {
                if (!$this->proofStore->consume($nonce, $binding)) {
                    return $this->reject();
                }
            } catch (\Throwable) {
                return $this->reject();
            }
        }

        return null;
    }

    /**
     * @param list<FormElementInterface> $elements
     */
    private function clearValues(array $elements): void
    {
        $formState = $this->finisherContext->getFormRuntime()->getFormState();
        foreach ($elements as $element) {
            $nonce = $formState?->getFormValue($element->getIdentifier());
            if (is_string($nonce)) {
                $this->proofStore->revoke($nonce);
            }
            $formState?->setFormValue($element->getIdentifier(), null);
            $this->renderedSitekeyStore->forget(
                $formState,
                $this->finisherContext->getFormRuntime()->getIdentifier(),
                $element->getIdentifier(),
            );
        }
    }

    private function reject(): string
    {
        $this->finisherContext->cancel();
        try {
            $message = $this->translationService->translate(
                'LLL:EXT:private_captcha/Resources/Private/Language/locallang_form.xlf:validation.verificationFailed',
                defaultValue: 'The CAPTCHA could not be verified. Please try again.',
            );
        } catch (\Throwable) {
            $message = null;
        }
        $error = GeneralUtility::makeInstance(TagBuilder::class, 'p');
        $error->addAttribute('class', 'private-captcha-error');
        $error->setContent(htmlspecialchars(
            is_string($message) ? $message : 'The CAPTCHA could not be verified. Please try again.',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        ));

        return $error->render();
    }
}
