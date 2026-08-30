<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Form;

use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Form\FormProofBinding;
use PrivateCaptcha\Typo3\Form\FormProofStore;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FormProofStoreTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = true;

    #[Test]
    public function proofCannotBeConsumedByAnotherPersistedFormDefinition(): void
    {
        $nonce = str_repeat('a', 64);
        $firstForm = new FormProofBinding(
            'session',
            'site',
            'form',
            'element',
            'sitekey',
            'form-a.form.yaml',
        );
        $secondForm = new FormProofBinding(
            'session',
            'site',
            'form',
            'element',
            'sitekey',
            'form-b.form.yaml',
        );
        $proofStore = $this->get(FormProofStore::class);
        $proofStore->issue($nonce, $firstForm);

        self::assertFalse($proofStore->consume($nonce, $secondForm));
        self::assertTrue($proofStore->consume($nonce, $firstForm));
    }
}
