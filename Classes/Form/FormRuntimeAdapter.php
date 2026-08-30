<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\SolutionVault;
use PrivateCaptcha\Typo3\Service\WidgetRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Domain\Model\FormElements\FormElementInterface;
use TYPO3\CMS\Form\Domain\Model\FormElements\Page;
use TYPO3\CMS\Form\Domain\Model\Renderable\AbstractRenderable;
use TYPO3\CMS\Form\Domain\Model\Renderable\RenderableInterface;
use TYPO3\CMS\Form\Domain\Model\Renderable\RootRenderableInterface;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;

/**
 * @internal
 */
final readonly class FormRuntimeAdapter
{
    private const ELEMENT_TYPE = 'PrivateCaptcha';

    public function __construct(
        private ConfigurationResolver $configurationResolver,
        private FormFieldNameResolver $fieldNameResolver,
        private FormProofStore $proofStore,
        private FormRenderedSitekeyStore $renderedSitekeyStore,
        private FormRequestSanitizer $requestSanitizer,
        private SolutionVault $solutionVault,
        private WidgetRenderer $widgetRenderer,
    ) {}

    public function afterFormIsBuilt(object $event): void
    {
        $form = $this->publicEventProperty($event, 'form');
        if (!$form instanceof FormDefinition) {
            return;
        }
        foreach ($form->getRenderablesRecursively() as $renderable) {
            $this->afterBuildingFinished($renderable);
        }
    }

    public function afterBuildingFinished(RenderableInterface $renderable): void
    {
        if (!$this->isPrivateCaptchaElement($renderable)) {
            return;
        }

        $options = [
            'formIdentifier' => $renderable->getRootForm()->getIdentifier(),
            'elementIdentifier' => $renderable->getIdentifier(),
            'formPersistenceIdentifier' => $renderable->getRootForm()->getPersistenceIdentifier(),
        ];
        $configuredValidator = null;
        $validators = $renderable->getValidators();
        foreach ($validators as $validator) {
            if (!$validator instanceof PrivateCaptchaValidator) {
                continue;
            }
            if ($configuredValidator === null) {
                $validator->setOptions($options);
                $configuredValidator = $validator;
                continue;
            }
            $validators->detach($validator);
        }

        if ($configuredValidator === null) {
            $validator = GeneralUtility::makeInstance(PrivateCaptchaValidator::class);
            $validator->setOptions($options);
            $validator->setRequest($renderable->getRequest());
            $renderable->addValidator($validator);
        }
    }

    /**
     * @param array<string, mixed> $requestArguments
     */
    public function afterSubmit(
        FormRuntime $formRuntime,
        RenderableInterface $renderable,
        mixed $elementValue,
        array $requestArguments = [],
    ): mixed {
        return $this->isPrivateCaptchaElement($renderable)
            ? $this->captureSubmission($formRuntime, $renderable)
            : $elementValue;
    }

    public function beforeRenderableIsValidated(object $event): void
    {
        $renderable = $this->publicEventProperty($event, 'renderable');
        if ($this->isPrivateCaptchaElement($renderable)) {
            $formRuntime = $this->publicEventProperty($event, 'formRuntime');
            if ($formRuntime instanceof FormRuntime) {
                (new \ReflectionProperty($event, 'value'))->setValue(
                    $event,
                    $this->captureSubmission($formRuntime, $renderable),
                );
            }
        }
    }

    public function afterCurrentPageIsResolved(object $event): void
    {
        $formRuntime = $this->publicEventProperty($event, 'formRuntime');
        if ($formRuntime instanceof FormRuntime) {
            $this->revokeProofsAfterBackwardNavigation(
                $formRuntime,
                $this->publicEventProperty($event, 'currentPage'),
                $this->publicEventProperty($event, 'lastDisplayedPage'),
            );
            $this->ensureGuardIsFirst($formRuntime);
        }
    }

    /**
     * @param array<string, mixed> $requestArguments
     */
    public function afterInitializeCurrentPage(
        FormRuntime $formRuntime,
        ?Page $currentPage,
        ?Page $lastDisplayedPage,
        array $requestArguments,
    ): ?Page {
        $this->revokeProofsAfterBackwardNavigation($formRuntime, $currentPage, $lastDisplayedPage);
        $this->ensureGuardIsFirst($formRuntime);

        return $currentPage;
    }

    public function beforeRendering(FormRuntime $formRuntime, RootRenderableInterface $renderable): void
    {
        $this->revokeProofsAfterValidationFailure($formRuntime, $renderable);
        $this->prepareRendering($formRuntime, $renderable);
    }

    public function beforeRenderableIsRendered(object $event): void
    {
        $formRuntime = $this->publicEventProperty($event, 'formRuntime');
        $renderable = $this->publicEventProperty($event, 'renderable');
        if ($formRuntime instanceof FormRuntime && $renderable instanceof RootRenderableInterface) {
            $this->revokeProofsAfterValidationFailure($formRuntime, $renderable);
            $this->prepareRendering($formRuntime, $renderable);
        }
    }

    private function prepareRendering(FormRuntime $formRuntime, RootRenderableInterface $renderable): void
    {
        if (!$this->isPrivateCaptchaElement($renderable)) {
            return;
        }
        $renderable->setProperty('privateCaptchaMarkup', '');
        if (!$renderable->isEnabled()) {
            $this->renderedSitekeyStore->forget(
                $formRuntime->getFormState(),
                $renderable->getRootForm()->getIdentifier(),
                $renderable->getIdentifier(),
            );
            return;
        }
        $this->revokeCurrentProof($formRuntime, $renderable);

        $request = $formRuntime->getRequest();
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            $this->forgetRenderedSitekey($formRuntime, $renderable);
            return;
        }
        try {
            $configuration = $this->configurationResolver->resolveSite($site);
        } catch (\InvalidArgumentException|\LogicException) {
            $this->forgetRenderedSitekey($formRuntime, $renderable);
            return;
        }
        if (!$configuration->requestedIntegrations->formFramework) {
            $renderable->setRenderingOption('enabled', false);
            $this->forgetRenderedSitekey($formRuntime, $renderable);
            return;
        }

        $formIdentifier = $renderable->getRootForm()->getIdentifier();
        $formPersistenceIdentifier = $renderable->getRootForm()->getPersistenceIdentifier();
        if ($formPersistenceIdentifier === '') {
            $this->forgetRenderedSitekey($formRuntime, $renderable);
            return;
        }
        $formState = $formRuntime->getFormState();
        if ($formState !== null) {
            $this->renderedSitekeyStore->remember(
                $formState,
                $site->getIdentifier(),
                $formPersistenceIdentifier,
                $formIdentifier,
                $renderable->getIdentifier(),
                $configuration->sitekey,
            );
        }

        $fieldName = $this->fieldNameResolver->fieldName(
            $request,
            $formIdentifier,
            $renderable->getIdentifier(),
        );
        $renderable->setProperty('privateCaptchaMarkup', $this->widgetRenderer->render(
            $configuration,
            $fieldName,
            $renderable->getUniqueIdentifier(),
            $request,
        ));
    }

    private function publicEventProperty(object $event, string $property): mixed
    {
        return get_object_vars($event)[$property] ?? null;
    }

    private function captureSubmission(FormRuntime $formRuntime, AbstractRenderable $renderable): string
    {
        $request = $formRuntime->getRequest();
        $formIdentifier = $renderable->getRootForm()->getIdentifier();
        $elementIdentifier = $renderable->getIdentifier();
        $solution = $this->fieldNameResolver->valueFromParsedBody(
            $request,
            $formIdentifier,
            $elementIdentifier,
        );
        $binding = null;
        $site = $request->getAttribute('site');
        $formSession = $formRuntime->getFormSession();
        $renderedSitekey = $this->renderedSitekeyStore->recall(
            $formRuntime->getFormState(),
            $site instanceof Site ? $site->getIdentifier() : '',
            $renderable->getRootForm()->getPersistenceIdentifier(),
            $formIdentifier,
            $elementIdentifier,
        );
        if ($site instanceof Site && $formSession !== null && $renderedSitekey !== null) {
            $binding = new FormProofBinding(
                $formSession->getIdentifier(),
                $site->getIdentifier(),
                $formIdentifier,
                $elementIdentifier,
                $renderedSitekey,
                $renderable->getRootForm()->getPersistenceIdentifier(),
            );
        }

        $this->requestSanitizer->sanitize($formRuntime, $request, [$renderable]);

        return $this->solutionVault->capture($solution, $binding);
    }

    private function forgetRenderedSitekey(FormRuntime $formRuntime, AbstractRenderable $renderable): void
    {
        $this->renderedSitekeyStore->forget(
            $formRuntime->getFormState(),
            $renderable->getRootForm()->getIdentifier(),
            $renderable->getIdentifier(),
        );
    }

    private function revokeCurrentProof(FormRuntime $formRuntime, AbstractRenderable $renderable): void
    {
        $formState = $formRuntime->getFormState();
        $nonce = $formState?->getFormValue($renderable->getIdentifier());
        if (is_string($nonce)) {
            $this->proofStore->revoke($nonce);
        }
        $formState?->setFormValue($renderable->getIdentifier(), null);
        $this->forgetRenderedSitekey($formRuntime, $renderable);
    }

    private function revokeProofsAfterBackwardNavigation(
        FormRuntime $formRuntime,
        mixed $currentPage,
        mixed $lastDisplayedPage,
    ): void {
        if ($currentPage instanceof Page
            && $lastDisplayedPage instanceof Page
            && $currentPage->getIndex() < $lastDisplayedPage->getIndex()
        ) {
            $this->revokeOutstandingProofs($formRuntime);
        }
    }

    private function revokeProofsAfterValidationFailure(
        FormRuntime $formRuntime,
        RootRenderableInterface $renderable,
    ): void {
        if (!$renderable instanceof FormDefinition) {
            return;
        }
        $extbase = $formRuntime->getRequest()->getAttribute('extbase');
        if ($extbase instanceof ExtbaseRequestParameters
            && $extbase->getOriginalRequestMappingResults()->hasErrors()
        ) {
            $this->revokeOutstandingProofs($formRuntime);
        }
    }

    private function revokeOutstandingProofs(FormRuntime $formRuntime): void
    {
        foreach ($formRuntime->getFormDefinition()->getRenderablesRecursively() as $renderable) {
            if (!$this->isPrivateCaptchaElement($renderable)) {
                continue;
            }
            $this->revokeCurrentProof($formRuntime, $renderable);
        }
    }

    private function ensureGuardIsFirst(FormRuntime $formRuntime): void
    {
        $form = $formRuntime->getFormDefinition();
        $hasCaptcha = false;
        foreach ($form->getRenderablesRecursively() as $renderable) {
            if ($renderable instanceof FormElementInterface && $renderable->getType() === self::ELEMENT_TYPE) {
                $hasCaptcha = true;
                break;
            }
        }
        if (!$hasCaptcha) {
            return;
        }

        $finishers = array_values(array_filter(
            $form->getFinishers(),
            static fn(mixed $finisher): bool => !$finisher instanceof PrivateCaptchaGuardFinisher,
        ));
        $form->setOptions(['finishers' => []], true);
        $guard = GeneralUtility::makeInstance(PrivateCaptchaGuardFinisher::class);
        $guard->setFinisherIdentifier('PrivateCaptchaGuard');
        $form->addFinisher($guard);
        foreach ($finishers as $finisher) {
            $form->addFinisher($finisher);
        }
    }

    /** @phpstan-assert-if-true FormElementInterface&AbstractRenderable $renderable */
    private function isPrivateCaptchaElement(mixed $renderable): bool
    {
        return $renderable instanceof FormElementInterface
            && $renderable instanceof AbstractRenderable
            && $renderable->getType() === self::ELEMENT_TYPE;
    }
}
