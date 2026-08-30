<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Form\Domain\Model\Renderable\AbstractRenderable;
use TYPO3\CMS\Form\Domain\Model\Renderable\RenderableInterface;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * @internal
 */
final readonly class FormRequestSanitizer
{
    public function __construct(
        private FormFieldNameResolver $fieldNameResolver,
    ) {}

    /**
     * @param list<RenderableInterface> $elements
     */
    public function sanitize(
        FormRuntime $formRuntime,
        RequestInterface $request,
        array $elements,
    ): RequestInterface {
        foreach ($elements as $element) {
            $request = $this->fieldNameResolver->withoutValue(
                $request,
                $formRuntime->getIdentifier(),
                $element->getIdentifier(),
            );
        }

        $contentObject = $request->getAttribute('currentContentObject');
        if ($contentObject instanceof ContentObjectRenderer) {
            $contentObject->setRequest($request->withoutAttribute('currentContentObject'));
        }
        $formRuntime->setRequest($request);
        $form = $formRuntime->getFormDefinition();
        $form->setRequest($request);
        foreach ($form->getRenderablesRecursively() as $renderable) {
            if ($renderable instanceof AbstractRenderable) {
                $renderable->setRequest($request);
            }
        }
        foreach ($form->getProcessingRules() as $processingRule) {
            foreach ($processingRule->getValidators() as $validator) {
                if (method_exists($validator, 'setRequest')) {
                    $validator->setRequest($request);
                }
            }
        }

        return $request;
    }
}
