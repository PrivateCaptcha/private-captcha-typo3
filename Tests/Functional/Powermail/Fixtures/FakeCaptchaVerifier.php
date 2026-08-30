<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Powermail\Fixtures;

use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;

final class FakeCaptchaVerifier implements CaptchaVerifierInterface
{
    /** @var list<mixed> */
    public static array $solutions = [];

    public function verify(
        #[\SensitiveParameter]
        mixed $solution,
        #[\SensitiveParameter]
        ResolvedCaptchaConfiguration $configuration,
    ): VerificationResult {
        self::$solutions[] = $solution;

        return $solution === 'accepted-solution'
            ? VerificationResult::accepted(0, null, 1, 5)
            : VerificationResult::rejected('provider-rejected');
    }
}
