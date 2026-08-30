<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Form;

use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Form\FormFieldNameResolver;
use PrivateCaptcha\Typo3\Form\FormProofBinding;
use PrivateCaptcha\Typo3\Form\FormProofStore;
use PrivateCaptcha\Typo3\Form\PrivateCaptchaValidator;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\Service\SolutionVault;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PrivateCaptchaValidatorTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = true;

    #[Test]
    public function verifiesOnlyTheConfiguredElementValueAgainstTheResolvedSitekey(): void
    {
        $site = $this->site(formFrameworkEnabled: true);
        $request = $this->request($site, [
            'contact' => [
                'captcha-a' => 'solution-a',
                'captcha-b' => 'solution-b',
            ],
        ]);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(
                'solution-b',
                self::callback(static fn(ResolvedCaptchaConfiguration $configuration): bool => $configuration->sitekey === 'site-property'),
            )
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        self::assertSame(
            'solution-b',
            $this->get(FormFieldNameResolver::class)->valueFromParsedBody($request, 'contact', 'captcha-b'),
        );

        $result = $this->validate($verifier, $request, 'contact', 'captcha-b');

        self::assertFalse(
            $result->hasErrors(),
            implode(', ', array_map(
                static fn(\TYPO3\CMS\Extbase\Error\Error $error): string => $error->getMessage(),
                $result->getErrors(),
            )),
        );
    }

    #[Test]
    public function rejectedVerificationAddsOnlyTheGenericVisitorError(): void
    {
        $request = $this->request($this->site(formFrameworkEnabled: true), [
            'contact' => ['captcha' => 'rejected-solution'],
        ]);
        $verifier = self::createStub(CaptchaVerifierInterface::class);
        $verifier->method('verify')->willReturn(VerificationResult::rejected('provider-rejected'));

        $result = $this->validate($verifier, $request);

        self::assertTrue($result->hasErrors());
        self::assertSame(
            ['The CAPTCHA could not be verified. Please try again.'],
            array_map(static fn(\TYPO3\CMS\Extbase\Error\Error $error): string => $error->getMessage(), $result->getErrors()),
        );
    }

    #[Test]
    public function enabledFormIntegrationWithMissingCredentialsFailsClosed(): void
    {
        $request = $this->request($this->site(
            formFrameworkEnabled: true,
            apiKey: '',
            sitekey: '',
        ), [
            'contact' => ['captcha' => 'solution'],
        ]);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');

        $result = $this->validate($verifier, $request);

        self::assertTrue($result->hasErrors());
    }

    private function validate(
        CaptchaVerifierInterface $verifier,
        Request $request,
        string $formIdentifier = 'contact',
        string $elementIdentifier = 'captcha',
    ): \TYPO3\CMS\Extbase\Error\Result {
        $site = $request->getAttribute('site');
        $siteConfiguration = $site instanceof Site ? $site->getConfiguration() : [];
        $privateCaptchaConfiguration = $siteConfiguration['privateCaptcha'] ?? [];
        $sitekey = is_array($privateCaptchaConfiguration)
            && is_string($privateCaptchaConfiguration['sitekey'] ?? null)
            ? $privateCaptchaConfiguration['sitekey']
            : '';
        $vault = new SolutionVault();
        $nonce = $vault->capture(
            $this->get(FormFieldNameResolver::class)->valueFromParsedBody(
                $request,
                $formIdentifier,
                $elementIdentifier,
            ),
            $site instanceof Site ? new FormProofBinding(
                'form-session',
                $site->getIdentifier(),
                $formIdentifier,
                $elementIdentifier,
                $sitekey,
                'test:contact',
            ) : null,
        );
        $validator = new PrivateCaptchaValidator(
            $this->get(ConfigurationResolver::class),
            $verifier,
            $this->get(FormProofStore::class),
            $vault,
        );
        $validator->setOptions([
            'formIdentifier' => $formIdentifier,
            'elementIdentifier' => $elementIdentifier,
            'formPersistenceIdentifier' => 'test:contact',
        ]);
        $validator->setRequest($request);

        return $validator->validate($nonce);
    }

    /**
     * @param array<string, mixed> $formArguments
     */
    private function request(?Site $site, array $formArguments): Request
    {
        $extbase = (new ExtbaseRequestParameters())
            ->setControllerExtensionName('Form')
            ->setPluginName('Formframework')
            ->setArguments($formArguments);
        $parsedBody = ['tx_form_formframework' => $formArguments];
        $serverRequest = (new ServerRequest('https://site-a.test/contact', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('extbase', $extbase)
            ->withParsedBody($parsedBody);
        if ($site !== null) {
            $serverRequest = $serverRequest->withAttribute('site', $site);
        }

        return new Request($serverRequest);
    }

    private function site(
        bool $formFrameworkEnabled,
        ?string $apiKey = null,
        string $sitekey = 'site-property',
    ): Site {
        return new Site('site-a', 1, [
            'base' => 'https://site-a.test/',
            'privateCaptcha' => [
                'apiKey' => $apiKey ?? bin2hex(random_bytes(16)),
                'sitekey' => $sitekey,
                'formFrameworkEnabled' => $formFrameworkEnabled,
            ],
        ]);
    }
}
