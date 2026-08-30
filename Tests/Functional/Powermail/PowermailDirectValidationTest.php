<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Powermail;

use Composer\InstalledVersions;
use In2code\Powermail\Domain\Model\Answer;
use In2code\Powermail\Domain\Model\Field;
use In2code\Powermail\Domain\Model\Form;
use In2code\Powermail\Domain\Model\Mail;
use In2code\Powermail\Domain\Repository\FormRepository;
use In2code\Powermail\Domain\Service\ConfigurationService;
use In2code\Powermail\Domain\Validator\CustomValidator;
use In2code\Powermail\Utility\HashUtility;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Powermail\PowermailSubmissionSanitizerMiddleware;
use PrivateCaptcha\Typo3\Powermail\PrivateCaptchaValidatorListener;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\Service\SolutionVault;
use PrivateCaptcha\Typo3\Tests\Functional\Powermail\Fixtures\FakeCaptchaVerifier;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Error\Result;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Security\HashScope;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PowermailDirectValidationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
        'typo3conf/ext/private_captcha/Tests/Functional/Powermail/Fixtures/Extensions/powermail_test',
    ];

    private bool $compatiblePowermailInstalled;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    /** @var array<array-key, mixed> */
    private array $originalGet = [];

    /** @var array<array-key, mixed> */
    private array $originalPost = [];

    /** @var array<array-key, mixed> */
    private array $originalRequest = [];

    private ?CaptchaVerifierInterface $installedVerifier = null;

    protected function setUp(): void
    {
        $powermailVersion = InstalledVersions::isInstalled('in2code/powermail')
            ? InstalledVersions::getVersion('in2code/powermail')
            : null;
        $this->compatiblePowermailInstalled = (new Typo3Version())->getMajorVersion() === 13
            && is_string($powermailVersion)
            && version_compare($powermailVersion, '13.2.0', '>=')
            && version_compare($powermailVersion, '14.0.0', '<');
        if ($this->compatiblePowermailInstalled) {
            array_unshift($this->testExtensionsToLoad, 'in2code/powermail');
        }

        parent::setUp();

        if (!$this->compatiblePowermailInstalled) {
            return;
        }
        $this->importCSVDataSet(__DIR__ . '/Fixtures/DirectSubmission.csv');
        $this->setUpFrontendRootPage(1, [
            'EXT:private_captcha/Tests/Functional/Powermail/Fixtures/DirectSubmission.typoscript',
        ]);
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalRequest = $_REQUEST;
        foreach ([
            ConfigurationResolver::SITE_API_KEYS_ENV,
            ConfigurationResolver::BACKEND_API_KEY_ENV,
        ] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        if ($this->compatiblePowermailInstalled) {
            $_GET = $this->originalGet;
            $_POST = $this->originalPost;
            $_REQUEST = $this->originalRequest;
            foreach ($this->originalEnvironment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function acceptedSolutionAllowsDirectCreationAndScrubsEveryDownstreamSource(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            $this->assertAdapterUnavailable();
            return;
        }

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(
                'accepted-solution',
                self::callback(static fn(ResolvedCaptchaConfiguration $configuration): bool => $configuration->sitekey === 'direct-sitekey'),
            )
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));

        $outcome = $this->submit([
            'name' => 'Alice',
            'security' => 'accepted-solution',
        ], $this->site(true, 'api-key', 'direct-sitekey'), $verifier);

        self::assertFalse($outcome['result']->hasErrors());
        self::assertSame(['name' => 'Alice'], $this->answerValues($outcome['mail']));
        self::assertInstanceOf(Answer::class, $outcome['captchaAnswer']);
        self::assertSame('', $outcome['captchaAnswer']->getValue());
        self::assertStringNotContainsString('accepted-solution', serialize($outcome['beforeValidation']));
        self::assertSame('', $outcome['beforeValidation']['rawBody']);
        self::assertFalse($outcome['beforeValidation']['hasContentLength']);
        self::assertNotSame('Alice', $outcome['beforeValidation']['answer']);
    }

    #[Test]
    public function rejectedSolutionBlocksCreationAndStillScrubsTheAnswer(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with('rejected-solution', self::anything())
            ->willReturn(VerificationResult::rejected('provider-rejected'));

        $outcome = $this->submit([
            'name' => 'Alice',
            'security' => 'rejected-solution',
        ], $this->site(true), $verifier);

        self::assertTrue($outcome['result']->hasErrors());
        self::assertSame(['name' => 'Alice'], $this->answerValues($outcome['mail']));
        self::assertInstanceOf(Answer::class, $outcome['captchaAnswer']);
        self::assertSame('', $outcome['captchaAnswer']->getOriginalValue());
        self::assertStringNotContainsString('rejected-solution', serialize($outcome['beforeValidation']));
    }

    #[Test]
    public function missingSolutionAndUnavailableCredentialsBlockWithoutProviderCalls(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');

        $missing = $this->submit(
            ['name' => 'Alice'],
            $this->site(true, 'api-key', 'sitekey', 'powermail-missing'),
            $verifier,
        );
        $unavailable = $this->submit(
            ['name' => 'Alice', 'security' => 'must-not-be-verified'],
            $this->site(true, '', 'sitekey', 'powermail-unavailable'),
            $verifier,
        );

        self::assertTrue($missing['result']->hasErrors());
        self::assertTrue($unavailable['result']->hasErrors());
        self::assertSame(['name' => 'Alice'], $this->answerValues($unavailable['mail']));
        self::assertStringNotContainsString('must-not-be-verified', serialize($unavailable['beforeValidation']));
    }

    #[Test]
    public function explicitlyDisabledIntegrationBypassesVerificationButNeverRetainsSubmittedSolution(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');

        $outcome = $this->submit([
            'name' => 'Alice',
            'security' => 'disabled-solution',
        ], $this->site(false), $verifier);

        self::assertFalse($outcome['result']->hasErrors());
        self::assertSame(['name' => 'Alice'], $this->answerValues($outcome['mail']));
        self::assertStringNotContainsString('disabled-solution', serialize($outcome['beforeValidation']));
    }

    #[Test]
    public function missingFormIdentityIsRejectedAndScrubbedBeforePowermail(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $body = [
            'tx_powermail_pi1' => [
                'mail' => ['__identity' => '501'],
                'field' => [
                    'security' => 'identity-solution',
                    '__hp' => 'filled',
                ],
            ],
        ];
        $_POST = $body;
        $_REQUEST = $body;
        $request = (new ServerRequest('https://powermail-direct.test/'))
            ->withMethod('POST')
            ->withParsedBody($body);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->get(PowermailSubmissionSanitizerMiddleware::class)->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('identity-solution', serialize([$_POST, $_REQUEST]));
    }

    #[Test]
    public function querySourcedSolutionFailsClosedBeforePowermailForGetAndEmptyPost(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $arguments = [
            'tx_powermail_pi1' => [
                'mail' => ['form' => '100'],
                'field' => [
                    'name' => 'Alice',
                    'security' => 'query-solution',
                ],
            ],
        ];
        $query = http_build_query($arguments, '', '&', PHP_QUERY_RFC3986);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        foreach (['GET', 'POST'] as $method) {
            $_GET = $arguments;
            $_POST = [];
            $_REQUEST = $arguments;
            $request = (new ServerRequest('https://powermail-direct.test/?' . $query))
                ->withMethod($method)
                ->withQueryParams($arguments);

            $response = $this->get(PowermailSubmissionSanitizerMiddleware::class)->process($request, $handler);

            self::assertSame(400, $response->getStatusCode());
            self::assertStringNotContainsString('query-solution', serialize([$_GET, $_POST, $_REQUEST]));
        }
    }

    #[Test]
    public function queryFormIdentityCannotBlankAFieldFromTheBodyForm(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with('accepted-solution', self::anything())
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));

        $outcome = $this->submit([
            'name' => 'Alice',
            'security' => 'accepted-solution',
        ], $this->site(true), $verifier, queryFormUid: 200);

        self::assertFalse($outcome['result']->hasErrors());
        self::assertSame(['name' => 'Alice'], $this->answerValues($outcome['mail']));
    }

    #[Test]
    public function nonCanonicalCaptchaMarkerAliasIsRejectedBeforePowermail(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $aliases = [
            'SECURITY' => 'uppercase-solution',
            'security ' => 'trailing-solution',
        ];
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        foreach ($aliases as $marker => $solution) {
            $body = [
                'tx_powermail_pi1' => [
                    'mail' => ['form' => '100'],
                    'field' => [$marker => $solution],
                ],
            ];
            $_POST = $body;
            $_REQUEST = $body;
            $request = (new ServerRequest('https://powermail-direct.test/'))
                ->withMethod('POST')
                ->withParsedBody($body);

            $response = $this->get(PowermailSubmissionSanitizerMiddleware::class)->process($request, $handler);

            self::assertSame(400, $response->getStatusCode());
            self::assertStringNotContainsString($solution, serialize([$_POST, $_REQUEST]));
        }
    }

    #[Test]
    public function duplicateCaptchaAnswersAreAllScrubbedAndRejectedWithoutVerification(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');

        $outcome = $this->submit([
            'name' => 'Alice',
            'security' => 'duplicate-solution',
        ], $this->site(true), $verifier, duplicateCaptchaAnswer: true);

        self::assertTrue($outcome['result']->hasErrors());
        self::assertSame(['name' => 'Alice'], $this->answerValues($outcome['mail']));
        self::assertInstanceOf(Answer::class, $outcome['captchaAnswer']);
        self::assertSame('', $outcome['captchaAnswer']->getValue());
        self::assertStringNotContainsString('duplicate-solution', serialize($outcome['beforeValidation']));
    }

    #[Test]
    public function untrustedSubmissionFlowsFailClosedAndScrubWithoutVerification(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $missingReferrer = $this->extbaseParameters('create', 'form');
        $missingReferrer->setArgument('__referrer', []);
        $tamperedReferrer = $this->extbaseParameters('create', 'form');
        $tamperedReferrer->setArgument('__referrer', ['@request' => 'tampered']);
        $wrongExtension = $this->extbaseParameters('create', 'form');
        $wrongExtension->setControllerExtensionName('Other');
        $wrongController = $this->extbaseParameters('create', 'form');
        $wrongController->setControllerName('Other');
        $wrongReferrer = $this->extbaseParameters(
            'create',
            'form',
            referrerExtension: 'Other',
        );
        $wrongAction = $this->extbaseParameters('delete', 'form');
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');

        foreach ([
            'missing referrer' => $missingReferrer,
            'tampered referrer' => $tamperedReferrer,
            'wrong extension' => $wrongExtension,
            'wrong controller' => $wrongController,
            'wrong referrer' => $wrongReferrer,
            'wrong action' => $wrongAction,
        ] as $description => $parameters) {
            $outcome = $this->submit([
                'name' => 'Alice',
                'security' => 'untrusted-solution',
            ], $this->site(true), $verifier, extbaseParameters: $parameters);

            self::assertTrue($outcome['result']->hasErrors(), $description);
            self::assertSame(['name' => 'Alice'], $this->answerValues($outcome['mail']), $description);
            self::assertSame('', $outcome['captchaAnswer']?->getValue(), $description);
            self::assertStringNotContainsString(
                'untrusted-solution',
                serialize($outcome['beforeValidation']),
                $description,
            );
        }
    }

    #[Test]
    public function authenticatedOptinCompletionDoesNotRequireASecondCaptchaVerification(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        FakeCaptchaVerifier::$solutions = [];
        $mail = $this->mail(['name' => 'Alice']);
        $mail->_setProperty('uid', 501);
        $mail->setPid(1);
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerExtensionName('Powermail');
        $parameters->setControllerName('Form');
        $parameters->setControllerActionName('create');
        $parameters->setArgument('hash', HashUtility::getHash($mail));
        $validator = (new \ReflectionClass(CustomValidator::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(CustomValidator::class, $validator);
        $validator->setRequest((new ServerRequest('https://powermail-direct.test/'))
            ->withAttribute('site', $this->site(true))
            ->withAttribute('extbase', $parameters));

        $result = $validator->validate($mail);

        self::assertFalse($result->hasErrors());
        self::assertSame([], FakeCaptchaVerifier::$solutions);

        $invalidParameters = $this->extbaseParameters('create', 'form');
        $invalidParameters->setArgument('hash', 'invalid');
        $form = $mail->getForm();
        self::assertInstanceOf(Form::class, $form);
        $field = $this->field($form, 'security');
        self::assertInstanceOf(Field::class, $field);
        $invalidAnswer = new Answer();
        $invalidAnswer->setField($field);
        $invalidAnswer->setMail($mail);
        $invalidAnswer->setValue($this->get(SolutionVault::class)->capture(
            'must-not-be-verified',
            ['formUid' => 100, 'marker' => 'security'],
        ));
        $mail->addAnswer($invalidAnswer);
        $invalidValidator = (new \ReflectionClass(CustomValidator::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(CustomValidator::class, $invalidValidator);
        $invalidValidator->setRequest((new ServerRequest('https://powermail-direct.test/'))
            ->withMethod('POST')
            ->withAttribute('site', $this->site(true, identifier: 'powermail-optin-invalid'))
            ->withAttribute('extbase', $invalidParameters));

        self::assertTrue($invalidValidator->validate($mail)->hasErrors());
        self::assertSame([], FakeCaptchaVerifier::$solutions);
        self::assertSame(['name' => 'Alice'], $this->answerValues($mail));
        self::assertSame('', $invalidAnswer->getValue());
    }

    #[Test]
    #[IgnoreDeprecations]
    public function realPowermailControllerPersistsOnlyAcceptedSubmissionWithoutCaptchaAnswer(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $this->site(true);
        FakeCaptchaVerifier::$solutions = [];

        $acceptedResponse = $this->executeFrontendSubRequest($this->frontendSubmission('accepted-solution', 'Alice'));
        self::assertSame(200, $acceptedResponse->getStatusCode());
        self::assertStringNotContainsString('accepted-solution', (string)$acceptedResponse->getBody());
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertSame(1, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));

        $untrustedResponse = $this->executeFrontendSubRequest(
            $this->frontendSubmission('untrusted-solution', 'Eve', tamperTrustedProperties: true),
        );

        self::assertSame(200, $untrustedResponse->getStatusCode());
        self::assertStringNotContainsString('untrusted-solution', (string)$untrustedResponse->getBody());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        self::assertSame(1, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));
        self::assertSame([['field' => 102, 'value' => 'Alice']], $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_answer')
            ->executeQuery('SELECT field, value FROM tx_powermail_domain_model_answer')
            ->fetchAllAssociative());

        $rejectedResponse = $this->executeFrontendSubRequest($this->frontendSubmission('rejected-solution', 'Bob'));

        self::assertSame(200, $rejectedResponse->getStatusCode());
        self::assertStringNotContainsString('rejected-solution', (string)$rejectedResponse->getBody());
        self::assertSame(1, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));
        self::assertSame([['field' => 102, 'value' => 'Alice']], $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_answer')
            ->executeQuery('SELECT field, value FROM tx_powermail_domain_model_answer')
            ->fetchAllAssociative());

        $spamResponse = $this->executeFrontendSubRequest(
            $this->frontendSubmission('spam-solution', 'Mallory', honeypot: 'filled'),
        );

        self::assertSame(200, $spamResponse->getStatusCode());
        self::assertStringNotContainsString('spam-solution', (string)$spamResponse->getBody());
        self::assertSame(['accepted-solution', 'rejected-solution'], FakeCaptchaVerifier::$solutions);
        self::assertSame(1, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{
     *     result: Result,
     *     mail: Mail,
     *     captchaAnswer: Answer|null,
     *     beforeValidation: array{
     *         body: array<array-key, mixed>,
     *         query: array<array-key, mixed>,
     *         get: array<array-key, mixed>,
     *         post: array<array-key, mixed>,
     *         request: array<array-key, mixed>,
     *         rawBody: string,
     *         uri: string,
     *         hasContentLength: bool,
     *         answer: mixed
     *     }
     * }
     */
    private function submit(
        array $fields,
        Site $site,
        CaptchaVerifierInterface $verifier,
        string $action = 'create',
        string $referrerAction = 'form',
        int $queryFormUid = 0,
        bool $duplicateCaptchaAnswer = false,
        ?ExtbaseRequestParameters $extbaseParameters = null,
    ): array {
        $container = self::getContainer();
        self::assertInstanceOf(Container::class, $container);
        if (!$this->installedVerifier instanceof CaptchaVerifierInterface) {
            $container->set(CaptchaVerifierInterface::class, $verifier);
            $powermailConfiguration = self::createStub(ConfigurationService::class);
            $powermailConfiguration->method('getTypoScriptSettings')->willReturn([
                'main' => [
                    'form' => '100',
                    'confirmation' => '0',
                ],
            ]);
            $container->set(ConfigurationService::class, $powermailConfiguration);
            $this->installedVerifier = $verifier;
        } else {
            self::assertSame($this->installedVerifier, $verifier);
        }

        $body = [
            'tx_powermail_pi1' => [
                'mail' => ['form' => '100'],
                'field' => $fields,
            ],
        ];
        $query = $queryFormUid > 0 ? [
            'tx_powermail_pi1' => [
                'mail' => ['form' => (string)$queryFormUid],
            ],
        ] : [];
        $_GET = $query;
        $_POST = $body;
        $_REQUEST = array_replace_recursive($query, $body);
        $rawBody = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
        $bodyStream = new Stream('php://temp', 'rw');
        $bodyStream->write($rawBody);
        $uri = 'https://' . $site->getIdentifier() . '.test/';
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $request = (new ServerRequest($uri))
            ->withMethod('POST')
            ->withParsedBody($body)
            ->withQueryParams($query)
            ->withBody($bodyStream)
            ->withAttribute('site', $site)
            ->withAttribute('currentContentObject', $this->contentObject())
            ->withAttribute('extbase', $extbaseParameters ?? $this->extbaseParameters($action, $referrerAction));
        if ($rawBody !== '') {
            $request = $request->withHeader('Content-Length', (string)strlen($rawBody));
        }

        $outcome = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (ServerRequestInterface $sanitizedRequest) use (&$outcome, $duplicateCaptchaAnswer): Response {
                $parsedBody = $sanitizedRequest->getParsedBody();
                self::assertIsArray($parsedBody);
                $query = $sanitizedRequest->getQueryParams();
                $queryArguments = $query['tx_powermail_pi1'] ?? [];
                self::assertIsArray($queryArguments);
                $bodyArguments = $parsedBody['tx_powermail_pi1'] ?? [];
                self::assertIsArray($bodyArguments);
                $pluginArguments = array_replace_recursive($queryArguments, $bodyArguments);
                $submittedFields = $pluginArguments['field'] ?? null;
                self::assertIsArray($submittedFields);
                /** @var array<string, mixed> $submittedFields */
                $mail = $this->mail($submittedFields);
                $captchaAnswer = $this->answer($mail, 'security');
                if ($duplicateCaptchaAnswer && $captchaAnswer instanceof Answer) {
                    $captchaField = $captchaAnswer->getField();
                    self::assertInstanceOf(Field::class, $captchaField);
                    $duplicateAnswer = new Answer();
                    $duplicateAnswer->setField($captchaField);
                    $duplicateAnswer->setMail($mail);
                    $duplicateAnswer->setValue($captchaAnswer->getValue());
                    $mail->addAnswer($duplicateAnswer);
                }
                $beforeValidation = [
                    'body' => $parsedBody,
                    'query' => $query,
                    'get' => $_GET,
                    'post' => $_POST,
                    'request' => $_REQUEST,
                    'rawBody' => (string)$sanitizedRequest->getBody(),
                    'uri' => (string)$sanitizedRequest->getUri(),
                    'hasContentLength' => $sanitizedRequest->hasHeader('Content-Length'),
                    'answer' => $captchaAnswer?->getValue(),
                ];
                $validator = (new \ReflectionClass(CustomValidator::class))->newInstanceWithoutConstructor();
                self::assertInstanceOf(CustomValidator::class, $validator);
                $validator->setRequest($sanitizedRequest);
                $result = $validator->validate($mail);
                $outcome = [
                    'result' => $result,
                    'mail' => $mail,
                    'captchaAnswer' => $captchaAnswer,
                    'beforeValidation' => $beforeValidation,
                ];

                return new Response(statusCode: 204);
            });

        $response = $this->get(PowermailSubmissionSanitizerMiddleware::class)->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertIsArray($outcome);
        return $outcome;
    }

    private function assertAdapterUnavailable(): void
    {
        $container = self::getContainer();
        foreach ([
            PowermailSubmissionSanitizerMiddleware::class,
            PrivateCaptchaValidatorListener::class,
        ] as $className) {
            self::assertFalse($container->has($className));
            self::assertFalse(class_exists($className, false));
        }
    }

    private function frontendSubmission(
        string $solution,
        string $name,
        string $honeypot = '',
        bool $tamperTrustedProperties = false,
    ): InternalRequest {
        $formResponse = $this->executeFrontendSubRequest(
            (new InternalRequest('https://powermail-direct.test/'))->withPageId(1),
        );
        self::assertSame(200, $formResponse->getStatusCode(), (string)$formResponse->getBody());
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML((string)$formResponse->getBody());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $forms = (new \DOMXPath($document))->query('//form[contains(concat(" ", normalize-space(@class), " "), " powermail_form ")]');
        self::assertNotFalse($forms);
        $form = $forms->item(0);
        self::assertInstanceOf(\DOMElement::class, $form, (string)$formResponse->getBody());
        $body = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $inputName = $input->getAttribute('name');
            if ($inputName !== '') {
                parse_str($inputName . '=' . rawurlencode($input->getAttribute('value')), $value);
                $body = array_replace_recursive($body, $value);
            }
        }
        $pluginArguments = $body['tx_powermail_pi1'] ?? null;
        self::assertIsArray($pluginArguments);
        $fields = $pluginArguments['field'] ?? null;
        self::assertIsArray($fields);
        $fields['name'] = $name;
        $fields['security'] = $solution;
        $fields['__hp'] = $honeypot;
        $pluginArguments['field'] = $fields;
        if ($tamperTrustedProperties) {
            $pluginArguments['__trustedProperties'] = 'invalid';
        }
        $body['tx_powermail_pi1'] = $pluginArguments;
        $action = html_entity_decode($form->getAttribute('action'), ENT_QUOTES | ENT_HTML5);
        $cookies = [];
        foreach ($formResponse->getHeader('Set-Cookie') as $setCookie) {
            $cookie = explode(';', $setCookie, 2)[0];
            [$cookieName, $value] = array_pad(explode('=', $cookie, 2), 2, '');
            if ($cookieName !== '') {
                $cookies[$cookieName] = $value;
            }
        }
        $stream = new Stream('php://temp', 'rw');
        $stream->write(http_build_query($body, '', '&', PHP_QUERY_RFC3986));

        return (new InternalRequest('https://powermail-direct.test' . $action))
            ->withMethod('POST')
            ->withParsedBody($body)
            ->withBody($stream)
            ->withCookieParams($cookies);
    }

    private function extbaseParameters(
        string $action,
        string $referrerAction,
        string $referrerExtension = 'Powermail',
        string $referrerController = 'Form',
    ): ExtbaseRequestParameters {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerExtensionName('Powermail');
        $parameters->setControllerName('Form');
        $parameters->setControllerActionName($action);
        $referrerRequest = json_encode([
            '@extension' => $referrerExtension,
            '@controller' => $referrerController,
            '@action' => $referrerAction,
        ], JSON_THROW_ON_ERROR);
        $parameters->setArgument('__referrer', [
            '@request' => $this->get(HashService::class)->appendHmac(
                $referrerRequest,
                HashScope::ReferringRequest->prefix(),
            ),
        ]);

        return $parameters;
    }

    private function contentObject(): ContentObjectRenderer
    {
        $contentObject = self::createStub(ContentObjectRenderer::class);
        $contentObject->data = [
            'uid' => 1,
            'pi_flexform' => '',
        ];

        return $contentObject;
    }

    /**
     * @param array<string, mixed> $submittedFields
     */
    private function mail(array $submittedFields): Mail
    {
        $form = $this->get(FormRepository::class)->findByUid(100);
        self::assertInstanceOf(Form::class, $form);
        $mail = new Mail();
        $mail->setForm($form);
        foreach ($submittedFields as $marker => $value) {
            $field = $this->field($form, $marker);
            if (!$field instanceof Field) {
                continue;
            }
            $answer = new Answer();
            $answer->setField($field);
            $answer->setMail($mail);
            $answer->setValue($value);
            $mail->addAnswer($answer);
        }

        return $mail;
    }

    private function field(Form $form, string $marker): ?Field
    {
        foreach ($form->getFields() as $field) {
            if ($field->getMarker() === $marker) {
                return $field;
            }
        }

        return null;
    }

    private function answer(Mail $mail, string $marker): ?Answer
    {
        foreach ($mail->getAnswers() as $answer) {
            if (!$answer instanceof Answer) {
                throw new \RuntimeException('Powermail answer collection is invalid.');
            }
            if ($answer->getField()?->getMarker() === $marker) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function answerValues(Mail $mail): array
    {
        $values = [];
        foreach ($mail->getAnswers() as $answer) {
            if (!$answer instanceof Answer) {
                throw new \RuntimeException('Powermail answer collection is invalid.');
            }
            $marker = $answer->getField()?->getMarker();
            if (is_string($marker)) {
                $values[$marker] = $answer->getValue();
            }
        }

        return $values;
    }

    private function site(
        bool $powermailEnabled,
        string $apiKey = 'api-key',
        string $sitekey = 'sitekey',
        string $identifier = 'powermail-direct',
    ): Site {
        $this->get(SiteWriter::class)->write($identifier, [
            'rootPageId' => 1,
            'base' => 'https://' . $identifier . '.test/',
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
                'apiKey' => $apiKey,
                'sitekey' => $sitekey,
                'powermailEnabled' => $powermailEnabled,
            ],
        ]);
        $siteFinder = $this->get(SiteFinder::class);
        $siteFinder->siteConfigurationChanged();

        return $siteFinder->getSiteByIdentifier($identifier);
    }
}
