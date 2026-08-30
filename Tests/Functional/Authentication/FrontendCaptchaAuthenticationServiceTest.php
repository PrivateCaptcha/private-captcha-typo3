<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Authentication;

use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Authentication\FrontendCaptchaAuthenticationService;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\Nonce;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FrontendCaptchaAuthenticationServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->environmentVariableNames() as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FrontendLoginPages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/FrontendUsers.csv');
        $this->setUpFrontendRootPage(1, [
            'EXT:private_captcha/Tests/Functional/Authentication/Fixtures/FrontendAuthentication.typoscript',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        parent::tearDown();
    }

    /**
     * @param non-empty-string $username
     * @param non-empty-string $password
     */
    #[Test]
    #[DataProvider('unsuccessfulAuthenticationProvider')]
    public function captchaAndCredentialFailuresNeverAuthenticate(
        string $username,
        string $password,
        ?string $solution,
        bool $captchaAccepted,
    ): void {
        $this->writeFrontendSite(true);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(
                $solution,
                self::isInstanceOf(ResolvedCaptchaConfiguration::class),
            )
            ->willReturn($captchaAccepted
                ? VerificationResult::accepted(0, null, 1, 5)
                : VerificationResult::rejected('provider-rejected'));
        $this->replaceAuthenticationServiceVerifier($verifier);

        $response = $this->executeFrontendSubRequest($this->loginRequest(
            $username,
            $password,
            $solution,
        ));

        self::assertNull($this->frontendSessionCookie($response));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, ?string, bool}>
     */
    public static function unsuccessfulAuthenticationProvider(): iterable
    {
        yield 'valid credentials and rejected CAPTCHA' => ['testuser', 'test', 'rejected-solution', false];
        yield 'wrong password and accepted CAPTCHA' => ['testuser', 'wrong-password', 'accepted-solution', true];
        yield 'unknown username and accepted CAPTCHA' => ['unknown-user', 'test', 'accepted-solution', true];
        yield 'unknown username and rejected CAPTCHA' => ['unknown-user', 'test', 'rejected-solution', false];
        yield 'missing CAPTCHA solution from custom form' => ['testuser', 'test', null, false];
    }

    #[Test]
    public function disabledIntegrationBypassesOnlyCaptchaAuthentication(): void
    {
        $this->writeFrontendSite(false);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceAuthenticationServiceVerifier($verifier);

        $response = $this->executeFrontendSubRequest($this->loginRequest('testuser', 'test'));

        self::assertNotNull($this->frontendSessionCookie($response));
    }

    #[Test]
    public function requestedIntegrationWithoutCredentialsFailsClosed(): void
    {
        $this->writeFrontendSite(true, apiKey: '', sitekey: '');
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceAuthenticationServiceVerifier($verifier);

        $response = $this->executeFrontendSubRequest($this->loginRequest(
            'testuser',
            'test',
            'ignored-solution',
        ));

        self::assertNull($this->frontendSessionCookie($response));
    }

    #[Test]
    public function verifierErrorsFailClosed(): void
    {
        $this->writeFrontendSite(true);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willThrowException(new \RuntimeException('Verifier unavailable'));
        $this->replaceAuthenticationServiceVerifier($verifier);

        $response = $this->executeFrontendSubRequest($this->loginRequest(
            'testuser',
            'test',
            'solution',
        ));

        self::assertNull($this->frontendSessionCookie($response));
    }

    #[Test]
    #[DataProvider('knownAndUnknownUsernameProvider')]
    public function resolverErrorsFailClosedForKnownAndUnknownUsers(string $username): void
    {
        $this->writeFrontendSite(true);
        putenv(ConfigurationResolver::SITE_API_KEYS_ENV . '=invalid-json');
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceAuthenticationServiceVerifier($verifier);

        $response = $this->executeFrontendSubRequest($this->loginRequest(
            $username,
            'test',
            'ignored-solution',
        ));

        self::assertNull($this->frontendSessionCookie($response));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function knownAndUnknownUsernameProvider(): iterable
    {
        yield 'known username' => ['testuser'];
        yield 'unknown username' => ['unknown-user'];
    }

    #[Test]
    public function existingFrontendSessionDoesNotRepeatCaptchaVerification(): void
    {
        $this->writeFrontendSite(true);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceAuthenticationServiceVerifier($verifier);
        $response = $this->executeFrontendSubRequest($this->loginRequest(
            'testuser',
            'test',
            'accepted-solution',
        ));
        $cookie = $this->frontendSessionCookie($response);
        self::assertNotNull($cookie);
        $request = (new ServerRequest('http://localhost/'))
            ->withAttribute('normalizedParams', $this->normalizedParams())
            ->withCookieParams([$cookie->getName() => $cookie->getValue()]);

        $frontendUserAuthentication = new FrontendUserAuthentication();
        $frontendUserAuthentication->setLogger(new NullLogger());
        $frontendUserAuthentication->start($request);

        self::assertIsArray($frontendUserAuthentication->user);
        self::assertSame('testuser', $frontendUserAuthentication->user['username'] ?? null);
    }

    #[Test]
    public function existingFrontendSessionDoesNotBypassCaptchaForAnotherLoginAttempt(): void
    {
        $this->writeFrontendSite(true);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with('rejected-solution', self::isInstanceOf(ResolvedCaptchaConfiguration::class))
            ->willReturn(VerificationResult::rejected('provider-rejected'));
        $request = (new ServerRequest('http://localhost/'))
            ->withMethod('POST')
            ->withParsedBody([Client::DEFAULT_FORM_FIELD => 'rejected-solution'])
            ->withAttribute('site', $this->get(SiteFinder::class)->getSiteByIdentifier('frontend-authentication'));
        $service = new FrontendCaptchaAuthenticationService(
            $this->get(ConfigurationResolver::class),
            $verifier,
        );
        $service->initAuth(
            'authUserFE',
            ['status' => 'login'],
            ['request' => $request, 'user' => ['uid' => 1]],
            new FrontendUserAuthentication(),
        );

        self::assertSame(0, $service->authUser(['uid' => 2]));
    }

    private function loginRequest(
        string $username,
        string $password,
        ?string $solution = null,
    ): InternalRequest {
        $nonce = Nonce::create();
        $requestToken = RequestToken::create('core/user-auth/fe')->toHashSignedJwt($nonce);
        $body = [
            'user' => $username,
            'pass' => $password,
            'logintype' => 'login',
            '__RequestToken' => $requestToken,
        ];
        if ($solution !== null) {
            $body[Client::DEFAULT_FORM_FIELD] = $solution;
        }

        return (new InternalRequest())
            ->withPageId(1)
            ->withMethod('POST')
            ->withParsedBody($body)
            ->withAttribute('normalizedParams', $this->normalizedParams())
            ->withCookieParams([
                'typo3nonce_' . $nonce->getSigningIdentifier()->name => $nonce->toHashSignedJwt(),
            ]);
    }

    private function normalizedParams(): NormalizedParams
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        self::assertIsArray($typo3Configuration);
        $systemConfiguration = $typo3Configuration['SYS'] ?? null;
        self::assertIsArray($systemConfiguration);

        return new NormalizedParams(
            [
                'REQUEST_URI' => '/',
                'HTTP_HOST' => 'localhost',
                'DOCUMENT_ROOT' => Environment::getPublicPath(),
                'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
                'SCRIPT_NAME' => '/index.php',
            ],
            $systemConfiguration,
            Environment::getPublicPath() . '/index.php',
            Environment::getPublicPath(),
        );
    }

    private function writeFrontendSite(
        bool $enabled,
        ?string $apiKey = null,
        string $sitekey = 'frontend-login-property',
    ): void {
        $this->get(SiteWriter::class)->write('frontend-authentication', [
            'rootPageId' => 1,
            'base' => 'http://localhost/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
            'privateCaptcha' => [
                'apiKey' => $apiKey ?? bin2hex(random_bytes(16)),
                'sitekey' => $sitekey,
                'frontendLoginEnabled' => $enabled ? '1' : '0',
            ],
        ]);
    }

    private function replaceAuthenticationServiceVerifier(CaptchaVerifierInterface $verifier): void
    {
        GeneralUtility::addInstance(
            FrontendCaptchaAuthenticationService::class,
            new FrontendCaptchaAuthenticationService(
                $this->get(ConfigurationResolver::class),
                $verifier,
            ),
        );
    }

    private function frontendSessionCookie(ResponseInterface $response): ?SetCookie
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, 'fe_typo_user=')) {
                $cookie = SetCookie::fromString($header);
                if ($cookie->getValue() !== 'deleted' && $cookie->getMaxAge() !== 0) {
                    return $cookie;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function environmentVariableNames(): array
    {
        return [
            ConfigurationResolver::SITE_API_KEYS_ENV,
            ConfigurationResolver::BACKEND_API_KEY_ENV,
            ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV,
        ];
    }
}
