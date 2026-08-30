<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Service;

use PrivateCaptcha\Typo3\Configuration\BackendConfigurationRepository;
use PrivateCaptcha\Typo3\Configuration\ConfigurationNormalizer;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Configuration\CustomDomainValidator;
use PrivateCaptcha\Typo3\Configuration\SiteConfigurationRepository;
use PrivateCaptcha\Typo3\ValueObject\CaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ConnectionTestResult;
use PrivateCaptcha\Typo3\ValueObject\IntegrationConfiguration;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\SettingsSubmission;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * @internal
 */
final readonly class SettingsActivationService
{
    public function __construct(
        private ConfigurationNormalizer $configurationNormalizer,
        private CustomDomainValidator $customDomainValidator,
        private ConfigurationResolver $configurationResolver,
        private ConnectionTester $connectionTester,
        private SiteConfigurationRepository $siteConfigurationRepository,
        private BackendConfigurationRepository $backendConfigurationRepository,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        private ?ClockInterface $clock = null,
    ) {}

    public function save(#[\SensitiveParameter] SettingsSubmission $submission): ConnectionTestResult
    {
        $locker = $this->acquireSettingsLocker($submission);

        try {
            [$candidate, $testCandidate, $requestedIntegrations] = $this->candidate($submission);
            $connectionTest = $this->connectionTester->test($this->resolve($submission, $testCandidate));
            $integrations = $connectionTest->successful
                ? $requestedIntegrations
                : IntegrationConfiguration::disabled();
            $candidate = $this->applyIntegrations($candidate, $integrations, $submission->isBackend());
            $metadata = $this->testMetadata($connectionTest);
            $candidate['lastConnectionTest'] = $metadata;

            $this->persist($submission, $candidate);
            $this->logConnectionTest($submission, 'save', $connectionTest, $metadata);

            return $connectionTest;
        } finally {
            $locker->release();
        }
    }

    public function test(#[\SensitiveParameter] SettingsSubmission $submission): ConnectionTestResult
    {
        $locker = $this->acquireSettingsLocker($submission);

        try {
            [, $testCandidate] = $this->candidate($submission);
            $connectionTest = $this->connectionTester->test($this->resolve($submission, $testCandidate));
            $current = $submission->isBackend()
                ? $this->backendConfigurationRepository->getForEditing()
                : $this->siteConfigurationRepository->getForEditing($submission->site());
            $metadata = $this->testMetadata($connectionTest);
            $current['lastConnectionTest'] = $metadata;
            $this->persist($submission, $current);
            $this->logConnectionTest($submission, 'test', $connectionTest, $metadata);

            return $connectionTest;
        } finally {
            $locker->release();
        }
    }

    public function reset(#[\SensitiveParameter] SettingsSubmission $submission): void
    {
        $locker = $this->acquireSettingsLocker($submission);

        try {
            $this->persist($submission, []);
            $context = $this->scopeContext($submission);
            $context['action'] = 'reset';
            $this->logger->info('Private Captcha settings reset.', $context);
        } finally {
            $locker->release();
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function persist(
        #[\SensitiveParameter]
        SettingsSubmission $submission,
        #[\SensitiveParameter]
        array $configuration,
    ): void {
        if ($submission->isBackend()) {
            $this->backendConfigurationRepository->save($configuration);

            return;
        }

        $this->siteConfigurationRepository->save($submission->site(), $configuration);
    }

    /**
     * @return array{
     *     array<string, bool|string>,
     *     array<string, bool|string>,
     *     IntegrationConfiguration
     * }
     */
    private function candidate(#[\SensitiveParameter] SettingsSubmission $submission): array
    {
        $configuration = $this->configurationNormalizer->normalize($submission->input());
        $this->assertSafeSubmittedStrings($configuration);
        $current = $submission->isBackend()
            ? $this->backendConfigurationRepository->getForEditing()
            : $this->siteConfigurationRepository->getForEditing($submission->site());
        $apiKeyReplacement = $configuration->apiKeyReplacement();
        $apiKey = $apiKeyReplacement ?? $this->persistedApiKey($current);
        $testApiKey = $apiKey;
        if (!$submission->isBackend()
            && $apiKeyReplacement === null
            && $this->containsYamlPlaceholder($apiKey)
        ) {
            $testApiKey = $this->persistedApiKey(
                $this->siteConfigurationRepository->getFresh($submission->site()),
            );
        }
        $customRootDomain = $this->customDomainValidator->validate($configuration->customRootDomain);
        $currentCustomRootDomain = $this->currentCustomRootDomain($current);
        if ($customRootDomain !== $currentCustomRootDomain) {
            if ($apiKeyReplacement === null) {
                throw new \InvalidArgumentException('Changing the custom root domain requires an explicit API key.');
            }
            $apiKeyOverridden = $submission->isBackend()
                ? $this->configurationResolver->hasBackendApiKeyOverride()
                : $this->configurationResolver->hasSiteApiKeyOverride($submission->site());
            if ($apiKeyOverridden) {
                throw new \InvalidArgumentException('Environment API keys require an operator-configured custom root domain.');
            }
        }
        $requestedIntegrations = $configuration->integrations->forScope($submission->isBackend());
        $candidate = [
            'apiKey' => $apiKey,
            'sitekey' => $configuration->sitekey,
            'theme' => $configuration->widget->theme,
            'language' => $configuration->widget->language,
            'startMode' => $configuration->widget->startMode,
            'debug' => $configuration->widget->debug,
            'customStyles' => $configuration->widget->customStyles,
            'euIsolation' => $customRootDomain === '' && $configuration->euIsolation,
            'customRootDomain' => $customRootDomain,
        ];

        $candidate = $this->applyIntegrations($candidate, $requestedIntegrations, $submission->isBackend());
        $testCandidate = $candidate;
        $testCandidate['apiKey'] = $testApiKey;

        return [
            $candidate,
            $testCandidate,
            $requestedIntegrations,
        ];
    }

    /**
     * @param array<string, mixed> $current
     */
    private function currentCustomRootDomain(#[\SensitiveParameter] array $current): string
    {
        $customRootDomain = $current['customRootDomain'] ?? '';
        if (!is_string($customRootDomain)) {
            throw new \InvalidArgumentException('Persisted custom root domain must be a string.');
        }

        return $this->customDomainValidator->validate($customRootDomain);
    }

    private function assertSafeSubmittedStrings(
        #[\SensitiveParameter]
        CaptchaConfiguration $configuration,
    ): void {
        $values = [
            $configuration->sitekey,
            $configuration->widget->theme,
            $configuration->widget->language,
            $configuration->widget->startMode,
            $configuration->widget->customStyles,
            $configuration->customRootDomain,
        ];
        if ($configuration->apiKeyReplacement() !== null) {
            $values[] = $configuration->apiKeyReplacement();
        }

        foreach ($values as $value) {
            if ($value === '__UNSET' || $this->containsYamlPlaceholder($value)) {
                throw new \InvalidArgumentException('Settings must not contain TYPO3 configuration control values.');
            }
        }
    }

    private function containsYamlPlaceholder(#[\SensitiveParameter] string $value): bool
    {
        $match = preg_match('~(?:' . YamlFileLoader::PATTERN_PARTS . ')~u', $value);
        if ($match === false) {
            throw new \InvalidArgumentException('Settings must be valid text.');
        }

        return $match === 1;
    }

    /**
     * @param array<string, mixed> $current
     */
    private function persistedApiKey(#[\SensitiveParameter] array $current): string
    {
        $apiKey = $this->configurationNormalizer->normalizeApiKey($current['apiKey'] ?? '');
        if ($apiKey === ConfigurationNormalizer::UNCHANGED_API_KEY) {
            throw new \InvalidArgumentException('Persisted API key configuration uses a reserved value.');
        }

        return $apiKey;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function testMetadata(ConnectionTestResult $connectionTest): array
    {
        return [
            'testedAt' => ($this->clock?->now() ?? new \DateTimeImmutable())
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(DATE_ATOM),
            ...$connectionTest->diagnostics(),
        ];
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    private function logConnectionTest(
        SettingsSubmission $submission,
        string $action,
        ConnectionTestResult $connectionTest,
        array $metadata,
    ): void {
        $context = [
            ...$this->scopeContext($submission),
            'action' => $action,
            ...$metadata,
        ];
        $message = 'Private Captcha settings connection test completed.';
        if ($connectionTest->successful) {
            $this->logger->info($message, $context);

            return;
        }

        $this->logger->warning($message, $context);
    }

    /**
     * @return array{scope: string, siteIdentifier?: string}
     */
    private function scopeContext(SettingsSubmission $submission): array
    {
        if ($submission->isBackend()) {
            return ['scope' => 'backend'];
        }

        return [
            'scope' => 'site',
            'siteIdentifier' => $submission->site()->getIdentifier(),
        ];
    }

    private function lockIdentifier(SettingsSubmission $submission): string
    {
        if ($submission->isBackend()) {
            return BackendConfigurationRepository::LOCK_IDENTIFIER;
        }

        return 'private-captcha-settings-site-' . hash('sha256', $submission->site()->getIdentifier());
    }

    private function acquireSettingsLocker(SettingsSubmission $submission): LockingStrategyInterface
    {
        $locker = $this->lockFactory->createLocker(
            $this->lockIdentifier($submission),
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE
            | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
        );
        if (!$locker->acquire()) {
            throw new \RuntimeException('Unable to lock Private Captcha configuration.');
        }

        return $locker;
    }

    /**
     * @param array<string, bool|string> $candidate
     * @return array<string, bool|string>
     */
    private function applyIntegrations(
        #[\SensitiveParameter]
        array $candidate,
        IntegrationConfiguration $integrations,
        bool $backend,
    ): array {
        if ($backend) {
            $candidate['backendLoginEnabled'] = $integrations->backendLogin;

            return $candidate;
        }

        $candidate['formFrameworkEnabled'] = $integrations->formFramework;
        $candidate['powermailEnabled'] = $integrations->powermail;
        $candidate['frontendLoginEnabled'] = $integrations->frontendLogin;

        return $candidate;
    }

    /**
     * @param array<string, bool|string> $candidate
     */
    private function resolve(
        #[\SensitiveParameter]
        SettingsSubmission $submission,
        #[\SensitiveParameter]
        array $candidate,
    ): ResolvedCaptchaConfiguration {
        return $submission->isBackend()
            ? $this->configurationResolver->resolveBackendCandidate($candidate)
            : $this->configurationResolver->resolveSiteCandidate($submission->site(), $candidate);
    }
}
