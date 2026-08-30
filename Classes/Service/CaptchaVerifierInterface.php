<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;

/**
 * @internal
 */
interface CaptchaVerifierInterface
{
    public function verify(
        #[\SensitiveParameter]
        mixed $solution,
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $configuration,
    ): VerificationResult;
}
