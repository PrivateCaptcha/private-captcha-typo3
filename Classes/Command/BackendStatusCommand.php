<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Command;

use PrivateCaptcha\Typo3\Configuration\BackendConfigurationRepository;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
final class BackendStatusCommand extends Command
{
    public function __construct(
        private readonly BackendConfigurationRepository $backendConfigurationRepository,
        private readonly ConfigurationResolver $configurationResolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Private Captcha backend login status');

        $emergencyDisabled = $this->configurationResolver->backendLoginEmergencyDisabled();
        try {
            $resolved = $this->configurationResolver->resolveBackend();
        } catch (\Throwable) {
            $resolved = null;
        }
        try {
            $runtime = $this->backendConfigurationRepository->get();
        } catch (\Throwable) {
            $runtime = null;
        }
        try {
            $persisted = $this->backendConfigurationRepository->getForEditing();
        } catch (\Throwable) {
            $persisted = null;
        }

        $requestedState = is_array($runtime) ? $this->flagState($runtime, 'backendLoginEnabled') : 'invalid';
        $effectiveState = $resolved === null ? 'invalid' : ($resolved->integrations->backendLogin ? 'enabled' : 'disabled');
        $io->writeln('Protection requested: ' . $requestedState);
        $io->writeln('Protection effective: ' . $effectiveState);
        $io->writeln('Persisted protection: ' . (is_array($persisted) ? $this->flagState($persisted, 'backendLoginEnabled') : 'invalid'));
        $io->writeln('Configuration override: ' . $this->configurationOverrideState($persisted, $runtime, $emergencyDisabled));
        $io->writeln('Emergency disable: ' . ($emergencyDisabled ? 'active' : 'inactive'));
        $io->writeln('API key: ' . $this->apiKeyState($runtime));
        $io->writeln('Sitekey: ' . (is_array($runtime) ? $this->credentialState($runtime, 'sitekey') : 'invalid'));
        $io->writeln('Last connection test: ' . (is_array($persisted) ? $this->connectionTestState($persisted) : 'not tested'));

        if ($resolved === null) {
            $io->error('Runtime backend login configuration is invalid.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function apiKeyState(#[\SensitiveParameter] ?array $configuration): string
    {
        $environmentOverrideIsUsable = $this->configurationResolver->backendApiKeyOverrideIsUsable();
        if ($environmentOverrideIsUsable !== null) {
            return $environmentOverrideIsUsable
                ? 'configured (environment)'
                : 'invalid (environment)';
        }
        if ($configuration === null) {
            return 'invalid';
        }

        $state = $this->credentialState($configuration, 'apiKey');

        return $state === 'configured' ? 'configured (runtime configuration)' : $state;
    }

    /**
     * @param array<string, mixed>|null $persisted
     * @param array<string, mixed>|null $runtime
     */
    private function configurationOverrideState(
        ?array $persisted,
        ?array $runtime,
        bool $emergencyDisabled,
    ): string {
        if ($emergencyDisabled || $persisted === null || $runtime === null) {
            return 'not evaluated';
        }
        $persistedState = $this->flagState($persisted, 'backendLoginEnabled');
        $runtimeState = $this->flagState($runtime, 'backendLoginEnabled');
        if (!in_array($persistedState, ['enabled', 'disabled'], true)
            || !in_array($runtimeState, ['enabled', 'disabled'], true)
        ) {
            return 'not evaluated';
        }

        return $persistedState === $runtimeState ? 'inactive' : 'active';
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function credentialState(#[\SensitiveParameter] array $configuration, string $field): string
    {
        if (!array_key_exists($field, $configuration)
            || (is_string($configuration[$field]) && trim($configuration[$field]) === '')
        ) {
            return 'not configured';
        }

        return is_string($configuration[$field]) ? 'configured' : 'invalid';
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function flagState(array $configuration, string $field): string
    {
        if (!array_key_exists($field, $configuration)) {
            return 'disabled';
        }
        $value = $configuration[$field];
        if (in_array($value, [true, 1, '1', 'true', 'on'], true)) {
            return 'enabled';
        }
        if (in_array($value, [false, 0, '0', 'false', 'off', ''], true)) {
            return 'disabled';
        }

        return 'invalid';
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function connectionTestState(#[\SensitiveParameter] array $configuration): string
    {
        $metadata = $configuration['lastConnectionTest'] ?? null;
        if (!is_array($metadata)
            || !is_bool($metadata['successful'] ?? null)
            || !is_string($metadata['testedAt'] ?? null)
        ) {
            return 'not tested';
        }
        try {
            $testedAt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $metadata['testedAt']);
        } catch (\Throwable) {
            return 'not tested';
        }
        if (!$testedAt instanceof \DateTimeImmutable || $testedAt->format(DATE_ATOM) !== $metadata['testedAt']) {
            return 'not tested';
        }

        return sprintf(
            '%s at %s',
            $metadata['successful'] ? 'successful' : 'failed',
            $testedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s \\U\\T\\C'),
        );
    }
}
