<?php

declare(strict_types=1);

use In2code\Powermail\DataProcessor\DataProcessorRunner;
use In2code\Powermail\Events\CustomValidatorEvent;
use In2code\Powermail\Events\FormControllerFormActionEvent;
use PrivateCaptcha\Typo3\Compatibility\PowermailCompatibility;
use PrivateCaptcha\Typo3\Powermail\ConfirmationDataProcessorRunner;
use PrivateCaptcha\Typo3\Powermail\ConfirmationProofStore;
use PrivateCaptcha\Typo3\Powermail\PowermailRegistration;
use PrivateCaptcha\Typo3\Powermail\PowermailSubmissionSanitizerMiddleware;
use PrivateCaptcha\Typo3\Powermail\PrivateCaptchaValidatorListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Event\ModifyLoadedPageTsConfigEvent;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    if (!PowermailCompatibility::isComposerPackageAvailable(new Typo3Version())
        || !$containerBuilder->hasDefinition('In2code\\Powermail\\DataProcessor\\DataProcessorRunner')
    ) {
        return;
    }

    $services = $container->services();
    $services
        ->set(PowermailRegistration::class)
        ->autowire()
        ->public()
        ->tag('event.listener', [
            'identifier' => 'private-captcha/powermail-field-type',
            'event' => ModifyLoadedPageTsConfigEvent::class,
            'method' => 'addFieldType',
        ]);
    $services->set(ConfirmationProofStore::class)->autowire();
    $services
        ->set(PowermailSubmissionSanitizerMiddleware::class)
        ->autowire()
        ->public();
    $services
        ->set(ConfirmationDataProcessorRunner::class)
        ->decorate(DataProcessorRunner::class)
        ->args([
            service(ConfirmationDataProcessorRunner::class . '.inner'),
            service(PrivateCaptchaValidatorListener::class),
        ]);
    $services
        ->set(PrivateCaptchaValidatorListener::class)
        ->autowire()
        ->tag('event.listener', [
            'identifier' => 'private-captcha/powermail-direct-validation',
            'event' => CustomValidatorEvent::class,
        ])
        ->tag('event.listener', [
            'identifier' => 'private-captcha/powermail-confirmation-back',
            'event' => FormControllerFormActionEvent::class,
            'method' => 'revokeConfirmationProofsOnBack',
        ]);
};
