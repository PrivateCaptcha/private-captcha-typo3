<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Form;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Typo3\Form\FormRenderedSitekeyStore;
use Psr\Clock\ClockInterface;
use TYPO3\CMS\Form\Domain\Runtime\FormState;

final class FormRenderedSitekeyStoreTest extends TestCase
{
    #[Test]
    public function sitekeyIsLimitedToItsSiteFormAndLifetime(): void
    {
        $clock = new class (new \DateTimeImmutable('@1000')) implements ClockInterface {
            public function __construct(public \DateTimeImmutable $currentTime) {}

            public function now(): \DateTimeImmutable
            {
                return $this->currentTime;
            }
        };
        $store = new FormRenderedSitekeyStore($clock);
        $state = new FormState();
        $store->remember(
            $state,
            'site-a',
            'form-a.form.yaml',
            'contact',
            'captcha',
            'rendered-sitekey',
        );

        self::assertSame('rendered-sitekey', $store->recall(
            $state,
            'site-a',
            'form-a.form.yaml',
            'contact',
            'captcha',
        ));
        self::assertNull($store->recall(
            $state,
            'site-b',
            'form-a.form.yaml',
            'contact',
            'captcha',
        ));
        self::assertNull($store->recall(
            $state,
            'site-a',
            'form-b.form.yaml',
            'contact',
            'captcha',
        ));

        $clock->currentTime = new \DateTimeImmutable('@2801');
        self::assertNull($store->recall(
            $state,
            'site-a',
            'form-a.form.yaml',
            'contact',
            'captcha',
        ));
    }
}
