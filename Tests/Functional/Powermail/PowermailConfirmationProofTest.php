<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Powermail;

use Composer\InstalledVersions;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use In2code\Powermail\Domain\Model\Form;
use In2code\Powermail\Domain\Model\Mail;
use In2code\Powermail\Domain\Repository\FormRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Powermail\PrivateCaptchaValidatorListener;
use PrivateCaptcha\Typo3\Tests\Functional\Powermail\Fixtures\FakeCaptchaVerifier;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PowermailConfirmationProofTest extends FunctionalTestCase
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

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->cookies = [];
        $this->originalEnvironment = [];
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
            'EXT:private_captcha/Tests/Functional/Powermail/Fixtures/ConfirmationSubmission.typoscript',
        ]);
        foreach ([
            ConfigurationResolver::SITE_API_KEYS_ENV,
            ConfigurationResolver::BACKEND_API_KEY_ENV,
        ] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
        $this->writeSite('sitekey');
        FakeCaptchaVerifier::$solutions = [];
    }

    protected function tearDown(): void
    {
        if ($this->compatiblePowermailInstalled) {
            foreach ($this->originalEnvironment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            FakeCaptchaVerifier::$solutions = [];
        }

        parent::tearDown();
    }

    #[Test]
    #[IgnoreDeprecations]
    public function confirmationVerifiesOnceAndPersistsOnlyBusinessAnswers(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $flow = $this->beginConfirmation('process-me');
        $confirmationResponse = $flow['confirmationResponse'];

        self::assertSame(200, $confirmationResponse->getStatusCode());
        self::assertStringNotContainsString('accepted-solution', (string)$confirmationResponse->getBody());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        $finalRequest = $flow['finalRequest'];
        $proof = $flow['proof'];

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertSame(0, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));

        $finalResponse = $this->send($finalRequest);

        self::assertSame(200, $finalResponse->getStatusCode());
        self::assertStringNotContainsString('accepted-solution', (string)$finalResponse->getBody());
        self::assertStringNotContainsString($proof, (string)$finalResponse->getBody());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        self::assertSame(1, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));
        self::assertSame([['field' => 102, 'value' => 'PROCESS-ME']], $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_answer')
            ->executeQuery('SELECT field, value FROM tx_powermail_domain_model_answer')
            ->fetchAllAssociative());

        $replayResponse = $this->send($finalRequest);

        self::assertSame(200, $replayResponse->getStatusCode());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        self::assertSame(1, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));
    }

    #[Test]
    #[IgnoreDeprecations]
    public function expiredConfirmationProofCannotPersistMail(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $flow = $this->beginConfirmation();
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_privatecaptcha_formproof');
        self::assertSame(1, $connection->update('tx_privatecaptcha_formproof', ['expires_at' => 1], []));

        $response = $this->send($flow['finalRequest']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        $this->assertNoPersistedMail();
    }

    #[Test]
    #[IgnoreDeprecations]
    public function confirmationProofCannotCrossFrontendSessions(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $flow = $this->beginConfirmation();
        self::assertArrayHasKey('fe_typo_user', $this->cookies);
        unset($this->cookies['fe_typo_user']);

        $response = $this->send($flow['finalRequest']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        $this->assertNoPersistedMail();
    }

    #[Test]
    #[IgnoreDeprecations]
    public function confirmationProofCannotSurviveSitekeyChange(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $flow = $this->beginConfirmation();
        $this->writeSite('changed-sitekey');

        $response = $this->send($flow['finalRequest']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        $this->assertNoPersistedMail();
    }

    #[Test]
    #[IgnoreDeprecations]
    public function backNavigationRevokesTheConfirmationProof(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $flow = $this->beginConfirmation();
        $backResponse = $this->send($flow['backRequest']);

        self::assertSame(200, $backResponse->getStatusCode());
        self::assertStringNotContainsString('accepted-solution', (string)$backResponse->getBody());
        self::assertStringNotContainsString($flow['proof'], (string)$backResponse->getBody());

        $response = $this->send($flow['finalRequest']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        $this->assertNoPersistedMail();
    }

    #[Test]
    #[IgnoreDeprecations]
    public function confirmationEnabledFormCannotPostDirectlyToCreate(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $request = $this->initialSubmissionRequest('Alice');
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $query);
        $pluginArguments = $query['tx_powermail_pi1'] ?? null;
        self::assertIsArray($pluginArguments);
        $pluginArguments['action'] = 'create';
        $query['tx_powermail_pi1'] = $pluginArguments;
        $query['id'] = 1;
        unset($query['cHash']);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['cHash'] = $this->get(CacheHashCalculator::class)->generateForParameters($queryString);
        $request = $request->withUri($uri->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986)));

        $response = $this->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], FakeCaptchaVerifier::$solutions);
        $this->assertNoPersistedMail();
    }

    #[Test]
    #[IgnoreDeprecations]
    public function failedBusinessValidationCreatesNoDurableProof(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $response = $this->send($this->initialSubmissionRequest(''));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('accepted-solution', (string)$response->getBody());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        self::assertSame(0, $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_privatecaptcha_formproof')
            ->count('*', 'tx_privatecaptcha_formproof', []));
        $this->assertNoPersistedMail();
    }

    #[Test]
    #[IgnoreDeprecations]
    #[DataProvider('captchaBindingMutationProvider')]
    public function dataProcessorsCannotChangeTheVerifiedCaptchaBinding(string $mutation): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        try {
            $confirmationResponse = $this->send($this->initialSubmissionRequest($mutation));
            $finalRequest = $this->formRequest(
                $confirmationResponse,
                '//div[contains(concat(" ", normalize-space(@class), " "), " powermail_confirmation ")]//form[.//*[@data-powermail-form-ajax="submit"]]',
            );
            $this->send($finalRequest);
        } catch (\Throwable) {
        }

        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        self::assertSame(0, $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_privatecaptcha_formproof')
            ->count('*', 'tx_privatecaptcha_formproof', []));
        $this->assertNoPersistedMail();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function captchaBindingMutationProvider(): iterable
    {
        yield 'field marker changed' => ['mutate-binding'];
        yield 'captcha field removed' => ['remove-captcha-field'];
        yield 'form replaced' => ['replace-form'];
    }

    #[Test]
    #[IgnoreDeprecations]
    public function changedBusinessValuesCannotUseTheConfirmationProof(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $flow = $this->beginConfirmation();
        $request = $this->withFieldValue($flow['finalRequest'], 'name', 'Mallory');

        $response = $this->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['accepted-solution'], FakeCaptchaVerifier::$solutions);
        $this->assertNoPersistedMail();
    }

    #[Test]
    #[IgnoreDeprecations]
    public function explicitlyDisabledIntegrationLeavesConfirmationFormsUnprotected(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $this->writeSite('sitekey', false);
        $formResponse = $this->send((new InternalRequest('https://powermail-confirmation.test/'))->withPageId(1));
        $confirmationResponse = $this->send($this->formRequest(
            $formResponse,
            '//form[contains(concat(" ", normalize-space(@class), " "), " powermail_form ")]',
            ['name' => 'Alice', '__hp' => ''],
        ));
        $finalRequest = $this->formRequest(
            $confirmationResponse,
            '//div[contains(concat(" ", normalize-space(@class), " "), " powermail_confirmation ")]//form[.//*[@data-powermail-form-ajax="submit"]]',
        );

        $response = $this->send($finalRequest);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], FakeCaptchaVerifier::$solutions);
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertSame(1, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));
        self::assertSame([['field' => 102, 'value' => 'ALICE']], $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_answer')
            ->executeQuery('SELECT field, value FROM tx_powermail_domain_model_answer')
            ->fetchAllAssociative());
    }

    #[Test]
    public function confirmationListenerIgnoresFormsWithoutPrivateCaptchaFields(): void
    {
        if (!$this->compatiblePowermailInstalled) {
            self::assertFalse(class_exists(PrivateCaptchaValidatorListener::class, false));
            return;
        }

        $form = $this->get(FormRepository::class)->findByUid(300);
        self::assertInstanceOf(Form::class, $form);
        $mail = new Mail();
        $mail->setForm($form);
        $this->get(PrivateCaptchaValidatorListener::class)->issueConfirmationProofs(
            $mail,
            new ServerRequest('https://powermail-confirmation.test/'),
            [],
        );

        self::assertSame(0, $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_privatecaptcha_formproof')
            ->count('*', 'tx_privatecaptcha_formproof', []));
    }

    /**
     * @return array{
     *     confirmationResponse: ResponseInterface,
     *     finalRequest: InternalRequest,
     *     backRequest: InternalRequest,
     *     proof: string
     * }
     */
    private function beginConfirmation(string $name = 'Alice'): array
    {
        $confirmationResponse = $this->send($this->initialSubmissionRequest($name));
        $finalRequest = $this->formRequest(
            $confirmationResponse,
            '//div[contains(concat(" ", normalize-space(@class), " "), " powermail_confirmation ")]//form[.//*[@data-powermail-form-ajax="submit"]]',
        );
        $backRequest = $this->formRequest(
            $confirmationResponse,
            '//div[contains(concat(" ", normalize-space(@class), " "), " powermail_confirmation ")]//form[.//*[@data-powermail-form-ajax="confirmation"]]',
        );
        $proof = $this->privateCaptchaValue($finalRequest);
        self::assertNotSame('', $proof);
        self::assertNotSame('accepted-solution', $proof);

        return [
            'confirmationResponse' => $confirmationResponse,
            'finalRequest' => $finalRequest,
            'backRequest' => $backRequest,
            'proof' => $proof,
        ];
    }

    private function initialSubmissionRequest(string $name): InternalRequest
    {
        $formResponse = $this->send((new InternalRequest('https://powermail-confirmation.test/'))->withPageId(1));

        return $this->formRequest(
            $formResponse,
            '//form[contains(concat(" ", normalize-space(@class), " "), " powermail_form ")]',
            ['name' => $name, 'security' => 'accepted-solution', '__hp' => ''],
        );
    }

    private function privateCaptchaValue(InternalRequest $request): string
    {
        $body = $request->getParsedBody();
        self::assertIsArray($body);
        $pluginArguments = $body['tx_powermail_pi1'] ?? null;
        self::assertIsArray($pluginArguments);
        $fields = $pluginArguments['field'] ?? null;
        self::assertIsArray($fields);
        $value = $fields['security'] ?? null;
        self::assertIsString($value);

        return $value;
    }

    private function withFieldValue(InternalRequest $request, string $marker, string $value): InternalRequest
    {
        $body = $request->getParsedBody();
        self::assertIsArray($body);
        $pluginArguments = $body['tx_powermail_pi1'] ?? null;
        self::assertIsArray($pluginArguments);
        $fields = $pluginArguments['field'] ?? null;
        self::assertIsArray($fields);
        $fields[$marker] = $value;
        $pluginArguments['field'] = $fields;
        $body['tx_powermail_pi1'] = $pluginArguments;

        return $request->withParsedBody($body);
    }

    /**
     * @param array<string, string> $fieldValues
     */
    private function formRequest(
        ResponseInterface $response,
        string $formXPath,
        array $fieldValues = [],
    ): InternalRequest {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML((string)$response->getBody());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $forms = (new \DOMXPath($document))->query($formXPath);
        self::assertNotFalse($forms);
        $form = $forms->item(0);
        self::assertInstanceOf(\DOMElement::class, $form, (string)$response->getBody());
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
        $fields = $pluginArguments['field'] ?? [];
        self::assertIsArray($fields);
        foreach ($fieldValues as $marker => $value) {
            $fields[$marker] = $value;
        }
        $pluginArguments['field'] = $fields;
        $body['tx_powermail_pi1'] = $pluginArguments;
        $action = html_entity_decode($form->getAttribute('action'), ENT_QUOTES | ENT_HTML5);
        $actionUri = UriResolver::resolve(new Uri('https://powermail-confirmation.test/'), new Uri($action));
        $stream = new Stream('php://temp', 'rw');
        $stream->write(http_build_query($body, '', '&', PHP_QUERY_RFC3986));

        return (new InternalRequest((string)$actionUri))
            ->withMethod('POST')
            ->withParsedBody($body)
            ->withBody($stream);
    }

    private function send(InternalRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write(http_build_query($body, '', '&', PHP_QUERY_RFC3986));
            $request = $request->withBody($stream);
        }
        $response = $this->executeFrontendSubRequest($request->withCookieParams($this->cookies));
        foreach ($response->getHeader('Set-Cookie') as $setCookie) {
            $cookie = explode(';', $setCookie, 2)[0];
            [$name, $value] = array_pad(explode('=', $cookie, 2), 2, '');
            if ($name !== '') {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    private function assertNoPersistedMail(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertSame(0, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_mail')
            ->count('*', 'tx_powermail_domain_model_mail', []));
        self::assertSame(0, $connectionPool
            ->getConnectionForTable('tx_powermail_domain_model_answer')
            ->count('*', 'tx_powermail_domain_model_answer', []));
    }

    private function writeSite(string $sitekey, bool $powermailEnabled = true): void
    {
        $this->get(SiteWriter::class)->write('powermail-confirmation', [
            'rootPageId' => 1,
            'base' => 'https://powermail-confirmation.test/',
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
                'apiKey' => 'api-key',
                'sitekey' => $sitekey,
                'powermailEnabled' => $powermailEnabled,
            ],
        ]);
        $siteFinder = $this->get(SiteFinder::class);
        $siteFinder->siteConfigurationChanged();
    }
}
