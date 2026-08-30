<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Command;

use PrivateCaptcha\Typo3\Configuration\BackendConfigurationRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * @internal
 */
final class BackendDisableCommand extends Command
{
    public function __construct(
        private readonly BackendConfigurationRepository $backendConfigurationRepository,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $lockCapabilities = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE
                | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
            $locker = $this->lockFactory->createLocker(
                BackendConfigurationRepository::LOCK_IDENTIFIER,
                $lockCapabilities,
            );
            $acquired = $locker->acquire($lockCapabilities);
        } catch (\Throwable) {
            $io->error('Unable to lock Private Captcha backend configuration.');

            return Command::FAILURE;
        }
        if (!$acquired) {
            $io->error('Unable to lock Private Captcha backend configuration.');

            return Command::FAILURE;
        }

        try {
            $this->backendConfigurationRepository->disableLoginProtection();
        } catch (\Throwable) {
            $io->error('Unable to disable Private Captcha backend login protection.');

            return Command::FAILURE;
        } finally {
            $locker->release();
        }

        $io->success('Persisted backend login protection is disabled.');

        return Command::SUCCESS;
    }
}
