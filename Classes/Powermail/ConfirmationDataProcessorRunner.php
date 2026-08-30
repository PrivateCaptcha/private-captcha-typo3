<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Powermail;

use In2code\Powermail\DataProcessor\DataProcessorRunner;
use In2code\Powermail\Domain\Model\Mail;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * @internal
 */
final class ConfirmationDataProcessorRunner extends DataProcessorRunner
{
    public function __construct(
        private readonly DataProcessorRunner $inner,
        private readonly PrivateCaptchaValidatorListener $validatorListener,
    ) {}

    /**
     * @param array<string, mixed> $settings
     */
    public function callDataProcessors(
        Mail $mail,
        string $actionMethodName,
        array $settings,
        ContentObjectRenderer $contentObject,
    ): void {
        if ($actionMethodName !== 'confirmationAction') {
            $this->inner->callDataProcessors($mail, $actionMethodName, $settings, $contentObject);
            return;
        }

        $this->inner->callDataProcessors($mail, $actionMethodName, $settings, $contentObject);
        $this->validatorListener->issueConfirmationProofs(
            $mail,
            $contentObject->getRequest()->withAttribute('currentContentObject', $contentObject),
            $settings,
        );
    }
}
