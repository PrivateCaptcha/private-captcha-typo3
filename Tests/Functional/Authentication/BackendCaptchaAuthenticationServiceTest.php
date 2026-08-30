<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Authentication;

use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Authentication\BackendCaptchaAuthenticationService;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Security\SudoMode\PasswordVerification;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\BcryptPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendCaptchaAuthenticationServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private bool $hadExtensionConfiguration = false;

    private mixed $originalExtensionConfiguration = null;

    /** @var array<string, mixed> */
    private array $originalPasswordHashingConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->environmentVariableNames() as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $extensionConfigurations = $this->extensionConfigurations();
        $this->hadExtensionConfiguration = array_key_exists('private_captcha', $extensionConfigurations);
        $this->originalExtensionConfiguration = $extensionConfigurations['private_captcha'] ?? null;
        $this->removePrivateCaptchaExtensionConfiguration();

        $typo3Configuration = $this->typo3Configuration();
        $backendConfiguration = $typo3Configuration['BE'] ?? null;
        self::assertIsArray($backendConfiguration);
        $passwordHashingConfiguration = $backendConfiguration['passwordHashing'] ?? null;
        self::assertIsArray($passwordHashingConfiguration);
        /** @var array<string, mixed> $passwordHashingConfiguration */
        $this->originalPasswordHashingConfiguration = $passwordHashingConfiguration;
        $backendConfiguration['passwordHashing'] = [
            'className' => BcryptPasswordHash::class,
            'options' => ['cost' => 10],
        ];
        $typo3Configuration['BE'] = $backendConfiguration;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
        $this->insertBackendUser();
        $this->securityAspect()->setReceivedRequestToken(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if ($this->hadExtensionConfiguration) {
            $this->setPrivateCaptchaExtensionConfiguration($this->originalExtensionConfiguration);
        } else {
            $this->removePrivateCaptchaExtensionConfiguration();
        }
        $typo3Configuration = $this->typo3Configuration();
        $backendConfiguration = $typo3Configuration['BE'] ?? [];
        if (is_array($backendConfiguration)) {
            $backendConfiguration['passwordHashing'] = $this->originalPasswordHashingConfiguration;
            $typo3Configuration['BE'] = $backendConfiguration;
            $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
        }
        $this->securityAspect()->setReceivedRequestToken(null);

        parent::tearDown();
    }

    #[Test]
    public function emergencyDisableContinuesBeforeReadingMalformedPersistence(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => 'malformed']);
        putenv(ConfigurationResolver::DISABLE_BACKEND_LOGIN_ENV . '=true');
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $service = $this->directService($verifier, request: $this->backendLoginRequest(
            'backend-user',
            'correct-password',
            'ignored-solution',
        ));

        self::assertSame(100, $service->authUser(['uid' => 1]));
        self::assertTrue($service->mimicAuthUser());
    }

    #[Test]
    public function requestedIntegrationWithoutCredentialsFailsClosed(): void
    {
        $this->setActiveBackendConfiguration(['apiKey' => '', 'sitekey' => '']);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $service = $this->directService($verifier, request: $this->backendLoginRequest(
            'backend-user',
            'correct-password',
            'ignored-solution',
        ));

        self::assertSame(0, $service->authUser(['uid' => 1]));
        self::assertFalse($service->mimicAuthUser());
    }

    #[Test]
    public function unrelatedAuthenticationContextsNeverInvokeVerification(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => 'malformed']);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        foreach ([
            ['authUserFE', 'login'],
            ['authUserBE', 'logout'],
            ['authUserBE', 'sudo-mode'],
            ['authUserBE', ''],
        ] as [$mode, $status]) {
            $service = $this->directService($verifier, $mode, $status, request: null);
            self::assertSame(100, $service->authUser(['uid' => 1]));
            self::assertTrue($service->mimicAuthUser());
        }
    }

    #[Test]
    #[DataProvider('invalidLoginRequestProvider')]
    public function activeLoginRequiresPostRequest(?ServerRequestInterface $request): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $service = $this->directService($verifier, request: $request);

        self::assertSame(0, $service->authUser(['uid' => 1]));
        self::assertFalse($service->mimicAuthUser());
    }

    /**
     * @return iterable<string, array{?ServerRequestInterface}>
     */
    public static function invalidLoginRequestProvider(): iterable
    {
        yield 'missing request' => [null];
        yield 'GET request' => [new ServerRequest('https://localhost/typo3/')];
    }

    #[Test]
    public function solutionIsReadOnlyFromParsedPostBody(): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(null, self::isInstanceOf(ResolvedCaptchaConfiguration::class))
            ->willReturn(VerificationResult::rejected('missing-solution'));
        $request = $this->backendLoginRequest('backend-user', 'correct-password')
            ->withQueryParams([Client::DEFAULT_FORM_FIELD => 'query-solution']);
        $service = $this->directService($verifier, request: $request);

        self::assertSame(0, $service->authUser(['uid' => 1]));
    }

    #[Test]
    public function resolverAndVerifierErrorsFailClosed(): void
    {
        $this->setActiveBackendConfiguration(['customRootDomain' => 'https://invalid.example']);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $service = $this->directService($verifier, request: $this->backendLoginRequest(
            'backend-user',
            'correct-password',
            'solution',
        ));

        self::assertSame(0, $service->authUser(['uid' => 1]));

        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willThrowException(new \RuntimeException('Verifier unavailable'));
        $service = $this->directService($verifier, request: $this->backendLoginRequest(
            'backend-user',
            'correct-password',
            'solution',
        ));

        self::assertSame(0, $service->authUser(['uid' => 1]));
    }

    #[Test]
    #[DataProvider('authenticationChainProvider')]
    public function backendAuthenticationChainNeverBypassesCorePasswordVerification(
        string $username,
        string $password,
        ?string $solution,
        bool $captchaAccepted,
        bool $enabled,
        bool $expectsAuthenticated,
    ): void {
        $this->setActiveBackendConfiguration(['backendLoginEnabled' => $enabled ? '1' : '0']);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $invocation = $enabled ? $this->once() : $this->never();
        $verifier->expects($invocation)
            ->method('verify')
            ->with($solution, self::isInstanceOf(ResolvedCaptchaConfiguration::class))
            ->willReturn($captchaAccepted
                ? VerificationResult::accepted(0, null, 1, 5)
                : VerificationResult::rejected('provider-rejected'));
        $this->replaceAuthenticationServiceVerifier($verifier);

        $backendUser = $this->authenticate($this->backendLoginRequest($username, $password, $solution));

        self::assertSame($expectsAuthenticated ? 'backend-user' : null, $backendUser->getUserName());
    }

    /**
     * @return iterable<string, array{string, string, ?string, bool, bool, bool}>
     */
    public static function authenticationChainProvider(): iterable
    {
        yield 'accepted CAPTCHA and valid password' => [
            'backend-user', 'correct-password', 'accepted-solution', true, true, true,
        ];
        yield 'accepted CAPTCHA and wrong password' => [
            'backend-user', 'wrong-password', 'accepted-solution', true, true, false,
        ];
        yield 'rejected CAPTCHA and valid password' => [
            'backend-user', 'correct-password', 'rejected-solution', false, true, false,
        ];
        yield 'accepted CAPTCHA and unknown username' => [
            'unknown-user', 'correct-password', 'accepted-solution', true, true, false,
        ];
        yield 'rejected CAPTCHA and unknown username' => [
            'unknown-user', 'correct-password', 'rejected-solution', false, true, false,
        ];
        yield 'missing CAPTCHA solution' => [
            'backend-user', 'correct-password', null, false, true, false,
        ];
        yield 'disabled CAPTCHA and valid password' => [
            'backend-user', 'correct-password', null, false, false, true,
        ];
        yield 'disabled CAPTCHA and wrong password' => [
            'backend-user', 'wrong-password', null, false, false, false,
        ];
    }

    #[Test]
    public function existingBackendSessionDoesNotRepeatCaptchaVerification(): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceAuthenticationServiceVerifier($verifier);
        $backendUser = $this->authenticate($this->backendLoginRequest(
            'backend-user',
            'correct-password',
            'accepted-solution',
        ));
        self::assertSame('backend-user', $backendUser->getUserName());
        $response = $backendUser->appendCookieToResponse(new Response(), $this->normalizedParams());
        $sessionCookie = SetCookie::fromString($response->getHeaderLine('Set-Cookie'));
        self::assertNotSame('', $sessionCookie->getValue());
        $this->securityAspect()->setReceivedRequestToken(null);
        $request = (new ServerRequest('https://localhost/typo3/module/web'))
            ->withAttribute('normalizedParams', $this->normalizedParams())
            ->withCookieParams([
                BackendUserAuthentication::getCookieName() => $sessionCookie->getValue(),
            ]);

        $sessionUser = $this->authenticate($request, provideRequestToken: false);

        self::assertSame('backend-user', $sessionUser->getUserName());
    }

    #[Test]
    public function invalidRequestTokenStopsBeforeCaptchaVerification(): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceAuthenticationServiceVerifier($verifier);
        $this->securityAspect()->setReceivedRequestToken(false);

        $backendUser = $this->authenticate($this->backendLoginRequest(
            'backend-user',
            'correct-password',
            'ignored-solution',
        ), provideRequestToken: false);

        self::assertNull($backendUser->getUserName());
    }

    #[Test]
    public function nativePasswordResetRequestNeverInvokesCaptcha(): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceAuthenticationServiceVerifier($verifier);
        $request = (new ServerRequest('https://localhost/typo3/login/password-reset/initiate-reset'))
            ->withMethod('POST')
            ->withParsedBody(['email' => 'backend@example.test'])
            ->withAttribute('normalizedParams', $this->normalizedParams());

        $backendUser = $this->authenticate($request, provideRequestToken: false);

        self::assertNull($backendUser->getUserName());
    }

    #[Test]
    public function passwordOnlyAjaxReloginCannotBypassCaptcha(): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(null, self::isInstanceOf(ResolvedCaptchaConfiguration::class))
            ->willReturn(VerificationResult::rejected('missing-solution'));
        $this->replaceAuthenticationServiceVerifier($verifier);
        $request = $this->backendLoginRequest('backend-user', 'correct-password')
            ->withUri(new Uri('https://localhost/typo3/ajax/login'));

        $backendUser = $this->authenticate($request);

        self::assertNull($backendUser->getUserName());
    }

    #[Test]
    public function selectingAnotherLoginProviderCannotBypassCaptchaWithNativeCredentials(): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(null, self::isInstanceOf(ResolvedCaptchaConfiguration::class))
            ->willReturn(VerificationResult::rejected('missing-solution'));
        $this->replaceAuthenticationServiceVerifier($verifier);
        $request = $this->backendLoginRequest('backend-user', 'correct-password')
            ->withQueryParams(['loginProvider' => 'third-party-provider']);

        $backendUser = $this->authenticate($request);

        self::assertNull($backendUser->getUserName());
    }

    #[Test]
    #[DataProvider('sudoPasswordProvider')]
    public function sudoPasswordVerificationNeverInvokesCaptcha(string $password, bool $expected): void
    {
        $this->setActiveBackendConfiguration();
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceAuthenticationServiceVerifier($verifier);
        $backendUser = new BackendUserAuthentication();
        $backendUser->setLogger(new NullLogger());
        $backendUser->user = $this->backendUserRecord();
        $previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $result = $this->get(PasswordVerification::class)->verifyBackendUserPassword($password, $backendUser);
        } finally {
            if ($previousBackendUser instanceof BackendUserAuthentication) {
                $GLOBALS['BE_USER'] = $previousBackendUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }
        }

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sudoPasswordProvider(): iterable
    {
        yield 'valid password' => ['correct-password', true];
        yield 'wrong password' => ['wrong-password', false];
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function commandLineAuthenticationNeverInvokesCaptcha(): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => 'malformed']);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceAuthenticationServiceVerifier($verifier);

        $commandLineUser = new CommandLineUserAuthentication();
        $commandLineUser->setLogger(new NullLogger());
        $commandLineUser->backendCheckLogin();

        self::assertSame('_cli_', $commandLineUser->getUserName());
    }

    private function directService(
        CaptchaVerifierInterface $verifier,
        string $mode = 'authUserBE',
        string $status = 'login',
        ?ServerRequestInterface $request = null,
    ): BackendCaptchaAuthenticationService {
        $service = new BackendCaptchaAuthenticationService(
            $this->get(ConfigurationResolver::class),
            $verifier,
        );
        $service->initAuth(
            $mode,
            ['status' => $status],
            ['request' => $request],
            new BackendUserAuthentication(),
        );

        return $service;
    }

    private function replaceAuthenticationServiceVerifier(CaptchaVerifierInterface $verifier): void
    {
        GeneralUtility::addInstance(
            BackendCaptchaAuthenticationService::class,
            new BackendCaptchaAuthenticationService(
                $this->get(ConfigurationResolver::class),
                $verifier,
            ),
        );
    }

    private function authenticate(
        ServerRequestInterface $request,
        bool $provideRequestToken = true,
    ): BackendUserAuthentication {
        if ($provideRequestToken) {
            $this->securityAspect()->setReceivedRequestToken(RequestToken::create('core/user-auth/be'));
        }
        $backendUser = new BackendUserAuthentication();
        $backendUser->setLogger(new NullLogger());
        $previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['BE_USER'] = $backendUser;
        try {
            $backendUser->start($request);
        } finally {
            if ($previousBackendUser instanceof BackendUserAuthentication) {
                $GLOBALS['BE_USER'] = $previousBackendUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }
        }

        return $backendUser;
    }

    private function backendLoginRequest(
        string $username,
        string $password,
        ?string $solution = null,
    ): ServerRequestInterface {
        $body = [
            'username' => $username,
            'userident' => $password,
            'login_status' => 'login',
        ];
        if ($solution !== null) {
            $body[Client::DEFAULT_FORM_FIELD] = $solution;
        }

        return (new ServerRequest('https://localhost/typo3/'))
            ->withMethod('POST')
            ->withParsedBody($body)
            ->withAttribute('normalizedParams', $this->normalizedParams());
    }

    private function normalizedParams(): NormalizedParams
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        self::assertIsArray($typo3Configuration);
        $systemConfiguration = $typo3Configuration['SYS'] ?? null;
        self::assertIsArray($systemConfiguration);

        return new NormalizedParams(
            [
                'REQUEST_URI' => '/typo3/',
                'HTTP_HOST' => 'localhost',
                'HTTPS' => 'on',
                'DOCUMENT_ROOT' => Environment::getPublicPath(),
                'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
                'SCRIPT_NAME' => '/index.php',
            ],
            $systemConfiguration,
            Environment::getPublicPath() . '/index.php',
            Environment::getPublicPath(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function setActiveBackendConfiguration(array $overrides = []): void
    {
        $this->setPrivateCaptchaExtensionConfiguration(['backend' => array_replace([
            'apiKey' => bin2hex(random_bytes(16)),
            'sitekey' => 'backend-login-property',
            'backendLoginEnabled' => '1',
            'customRootDomain' => '',
        ], $overrides)]);
    }

    private function insertBackendUser(): void
    {
        $passwordHash = $this->get(PasswordHashFactory::class)
            ->getDefaultHashInstance('BE')
            ->getHashedPassword('correct-password');
        self::assertIsString($passwordHash);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'pid' => 0,
            'username' => 'backend-user',
            'password' => $passwordHash,
            'admin' => 1,
            'disable' => 0,
            'deleted' => 0,
            'starttime' => 0,
            'endtime' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function backendUserRecord(): array
    {
        $record = $this->get(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->select(['*'], 'be_users', ['uid' => 1])
            ->fetchAssociative();
        self::assertIsArray($record);

        /** @var array<string, mixed> $record */
        return $record;
    }

    private function securityAspect(): SecurityAspect
    {
        return SecurityAspect::provideIn($this->get(Context::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function extensionConfigurations(): array
    {
        $typo3Configuration = $this->typo3Configuration();
        $extensionConfigurations = $typo3Configuration['EXTENSIONS'] ?? [];
        if (!is_array($extensionConfigurations)) {
            return [];
        }

        /** @var array<string, mixed> $extensionConfigurations */
        return $extensionConfigurations;
    }

    /**
     * @return array<string, mixed>
     */
    private function typo3Configuration(): array
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            return [];
        }

        /** @var array<string, mixed> $typo3Configuration */
        return $typo3Configuration;
    }

    private function setPrivateCaptchaExtensionConfiguration(mixed $configuration): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            $typo3Configuration = [];
        }
        $extensionConfigurations = $this->extensionConfigurations();
        $extensionConfigurations['private_captcha'] = $configuration;
        $typo3Configuration['EXTENSIONS'] = $extensionConfigurations;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
    }

    private function removePrivateCaptchaExtensionConfiguration(): void
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3Configuration)) {
            return;
        }
        $extensionConfigurations = $this->extensionConfigurations();
        unset($extensionConfigurations['private_captcha']);
        $typo3Configuration['EXTENSIONS'] = $extensionConfigurations;
        $GLOBALS['TYPO3_CONF_VARS'] = $typo3Configuration;
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
