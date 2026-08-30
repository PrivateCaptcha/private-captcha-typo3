<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PrivateCaptcha\Typo3\Form\FormRenderedSitekeyStore;
use PrivateCaptcha\Typo3\Form\FormRuntimeAdapter;
use PrivateCaptcha\Typo3\Form\PrivateCaptchaValidator;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use PrivateCaptcha\Typo3\ValueObject\ResolvedCaptchaConfiguration;
use PrivateCaptcha\Typo3\ValueObject\VerificationResult;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\ExpressionLanguage\Resolver;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Form\Domain\Finishers\ClosureFinisher;
use TYPO3\CMS\Form\Domain\Finishers\FinisherContext;
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Domain\Model\FormElements\FormElementInterface;
use TYPO3\CMS\Form\Domain\Model\Renderable\AbstractRenderable;
use TYPO3\CMS\Form\Domain\Model\Renderable\RenderableVariantInterface;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use TYPO3\CMS\Form\Domain\Runtime\FormState;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Security\HashScope;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FormFinisherTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'form',
    ];

    protected array $testExtensionsToLoad = [
        'private-captcha/typo3',
    ];

    protected bool $initializeDatabase = true;

    #[Test]
    public function reinstallsTheMandatoryValidatorWhenPersistedConfigurationOmitsIt(): void
    {
        [$form, $element] = $this->formAndElement();
        $form->getProcessingRule('captcha')->removeAllValidators();

        $this->installMandatoryValidator($form, $element);
        $this->installMandatoryValidator($form, $element);

        $validators = iterator_to_array($element->getValidators());
        self::assertCount(1, $validators);
        self::assertInstanceOf(PrivateCaptchaValidator::class, $validators[0]);
        self::assertSame([
            'formIdentifier' => 'contact',
            'elementIdentifier' => 'captcha',
            'formPersistenceIdentifier' => 'test:contact',
        ], $validators[0]->getOptions());
    }

    #[Test]
    public function disabledIntegrationSuppressesFrontendRendering(): void
    {
        [$form, $element] = $this->formAndElement();
        $runtime = $this->runtime($this->request(formFrameworkEnabled: false));

        $this->get(FormRuntimeAdapter::class)->beforeRendering($runtime, $element);

        self::assertFalse($element->isEnabled());
        self::assertSame('', $element->getProperties()['privateCaptchaMarkup'] ?? '');
    }

    #[Test]
    #[DataProvider('summaryAndFinisherContextProvider')]
    public function summaryAndEmailContextsSuppressTheCaptchaElement(
        string $stepType,
        string $finisherIdentifier,
        bool $expectedSuppressed,
    ): void {
        [, $element] = $this->formAndElement();
        self::assertInstanceOf(AbstractRenderable::class, $element);
        $variants = $element->getVariants();
        self::assertCount(1, $variants);
        $variant = reset($variants);
        self::assertInstanceOf(RenderableVariantInterface::class, $variant);
        $matches = $variant->conditionMatches(GeneralUtility::makeInstance(Resolver::class, 'form', [
            'stepType' => $stepType,
            'finisherIdentifier' => $finisherIdentifier,
        ]));
        if ($matches) {
            $variant->apply();
        }

        self::assertSame($expectedSuppressed, $matches);
        self::assertSame(!$expectedSuppressed, $element->isEnabled());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function summaryAndFinisherContextProvider(): iterable
    {
        yield 'summary page' => ['SummaryPage', '', true];
        yield 'sender email' => ['', 'EmailToSender', true];
        yield 'receiver email' => ['', 'EmailToReceiver', true];
        yield 'normal rendering' => ['', '', false];
    }

    #[Test]
    public function productionFormConfigurationLoadsTheCurrentVersionAdapter(): void
    {
        if (version_compare((new Typo3Version())->getVersion(), '14.2.0', '<')) {
            $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
            self::assertIsArray($typo3Configuration);
            $frontendConfiguration = $typo3Configuration['FE'] ?? [];
            self::assertIsArray($frontendConfiguration);
            $setup = $frontendConfiguration['defaultTypoScript_setup'] ?? '';
            self::assertIsString($setup);
            self::assertStringContainsString(
                'EXT:private_captcha/Configuration/Form/PrivateCaptcha/config.yaml',
                $setup,
            );
            self::assertStringContainsString(
                'EXT:private_captcha/Configuration/Form/PrivateCaptcha/LegacyEditor.yaml',
                $setup,
            );
            return;
        }

        [, $element] = $this->formAndElement();
        self::assertSame('PrivateCaptcha', $element->getType());
    }

    #[Test]
    public function activeIntegrationBindsWidgetToTheElementScopedHiddenField(): void
    {
        [$form, $element] = $this->formAndElement();
        $runtime = $this->runtime($this->request(formFrameworkEnabled: true));

        $this->get(FormRuntimeAdapter::class)->beforeRendering($runtime, $element);

        self::assertTrue($element->isEnabled());
        $markup = $element->getProperties()['privateCaptchaMarkup'] ?? '';
        self::assertIsString($markup);
        self::assertStringContainsString(
            'data-solution-field="tx_form_formframework[contact][captcha]"',
            $markup,
        );
        self::assertStringContainsString('data-sitekey="site-property"', $markup);
    }

    #[Test]
    public function acceptedVerificationRunsFinishersWithoutExposingTheSolution(): void
    {
        $solution = 'accepted-one-time-solution';
        $request = $this->submissionRequest($solution);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRan = false;
        $finisherValues = [];
        $finisherRequest = null;
        $finisherRequestAliases = [];
        [$form, $element] = $this->submissionForm(
            $request,
            static function (FinisherContext $context) use (
                &$finisherRan,
                &$finisherValues,
                &$finisherRequest,
                &$finisherRequestAliases,
            ): void {
                $finisherRan = true;
                $finisherValues = $context->getFormValues();
                $finisherRequest = $context->getRequest();
                $form = $context->getFormRuntime()->getFormDefinition();
                $requestAliases = [$form->getRequest()];
                foreach ($form->getRenderablesRecursively() as $renderable) {
                    if ($renderable instanceof AbstractRenderable) {
                        $requestAliases[] = $renderable->getRequest();
                    }
                }
                foreach ($form->getProcessingRules() as $processingRule) {
                    foreach ($processingRule->getValidators() as $validator) {
                        if ($validator instanceof PrivateCaptchaValidator) {
                            $requestAliases[] = $validator->getRequest();
                        }
                    }
                }
                foreach ($requestAliases as $requestAlias) {
                    if ($requestAlias instanceof Request) {
                        $finisherRequestAliases[] = serialize([
                            $requestAlias->getArguments(),
                            $requestAlias->getParsedBody(),
                        ]);
                    }
                }
            },
        );

        $runtime = $form->bind($request);
        self::assertNull($runtime->getCurrentPage());
        $proofNonce = $runtime->getFormState()?->getFormValue($element->getIdentifier());
        self::assertIsString($proofNonce);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $proofNonce);
        self::assertStringNotContainsString($solution, serialize($runtime->getFormState()));

        $runtime->render();

        self::assertTrue($finisherRan);
        self::assertNull($finisherValues['captcha'] ?? null);
        self::assertStringNotContainsString($solution, serialize($finisherValues));
        self::assertInstanceOf(Request::class, $finisherRequest);
        self::assertStringNotContainsString($solution, serialize($finisherRequest->getArguments()));
        self::assertStringNotContainsString($solution, serialize($finisherRequest->getParsedBody()));
        self::assertStringNotContainsString($solution, (string)$finisherRequest->getBody());
        $contentObject = $finisherRequest->getAttribute('currentContentObject');
        self::assertInstanceOf(ContentObjectRenderer::class, $contentObject);
        $contentObjectRequest = $contentObject->getRequest();
        self::assertStringNotContainsString($solution, serialize($contentObjectRequest->getParsedBody()));
        self::assertStringNotContainsString($solution, (string)$contentObjectRequest->getBody());
        self::assertNotEmpty($finisherRequestAliases);
        foreach ($finisherRequestAliases as $requestAlias) {
            self::assertStringNotContainsString($solution, $requestAlias);
        }
        self::assertNull($runtime->getFormState()?->getFormValue($element->getIdentifier()));
    }

    #[Test]
    public function acceptedVerificationUsesTheSitekeyAuthenticatedDuringRendering(): void
    {
        $solution = 'sitekey-rotation-solution';
        $request = $this->submissionRequest(
            $solution,
            sitekey: 'rotated-sitekey',
            renderedSitekey: 'rendered-sitekey',
        );
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with(
                $solution,
                self::callback(static fn(ResolvedCaptchaConfiguration $configuration): bool => $configuration->sitekey === 'rendered-sitekey'),
            )
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRan = false;
        [$form] = $this->submissionForm(
            $request,
            static function () use (&$finisherRan): void {
                $finisherRan = true;
            },
        );

        $runtime = $form->bind($request);
        $runtime->render();

        self::assertTrue($finisherRan);
    }

    #[Test]
    public function duplicateCaptchaArgumentPathsAreScrubbedBeforeFinishers(): void
    {
        $solution = 'accepted-duplicate-path-solution';
        $request = $this->submissionRequest($solution, duplicateCaptchaSolution: $solution);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRequest = null;
        [$form] = $this->submissionForm(
            $request,
            static function (FinisherContext $context) use (&$finisherRequest): void {
                $finisherRequest = $context->getRequest();
            },
        );

        $runtime = $form->bind($request);
        $runtime->render();

        self::assertInstanceOf(Request::class, $finisherRequest);
        self::assertStringNotContainsString($solution, serialize($finisherRequest->getArguments()));
        self::assertStringNotContainsString($solution, serialize($finisherRequest->getParsedBody()));
    }

    #[Test]
    public function rejectedVerificationPreventsEveryFinisherAndDropsTheSolution(): void
    {
        $solution = 'rejected-one-time-solution';
        $request = $this->submissionRequest($solution);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::rejected('provider-rejected'));
        $this->replaceVerifier($verifier);
        $finisherRan = false;
        [$form, $element] = $this->submissionForm(
            $request,
            static function () use (&$finisherRan): void {
                $finisherRan = true;
            },
        );

        $runtime = $form->bind($request);

        self::assertNotNull($runtime->getCurrentPage());
        self::assertFalse($finisherRan);
        self::assertStringNotContainsString($solution, serialize($runtime->getFormState()));

    }

    #[Test]
    public function unavailableSiteContextKeepsTheGenericErrorRenderable(): void
    {
        $request = $this->submissionRequest('solution-without-site', includeSite: false);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceVerifier($verifier);
        [$form, $element] = $this->submissionForm($request, static function (): void {});

        $runtime = $form->bind($request);
        self::assertNotNull($runtime->getCurrentPage());
        $this->get(FormRuntimeAdapter::class)->beforeRendering($runtime, $element);

        self::assertTrue($element->isEnabled());
        $errors = $form->getProcessingRule('captcha')->getProcessingMessages()->getErrors();
        self::assertSame(
            ['The CAPTCHA could not be verified. Please try again.'],
            array_map(static fn($error): string => $error->getMessage(), $errors),
        );
    }

    #[Test]
    public function explicitlyDisabledIntegrationDoesNotBlockFinishersOrExposeSubmittedSolution(): void
    {
        $solution = 'ignored-disabled-solution';
        $request = $this->submissionRequest($solution, formFrameworkEnabled: false);
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');
        $this->replaceVerifier($verifier);
        $finisherRan = false;
        $finisherRequest = null;
        [$form, $element] = $this->submissionForm(
            $request,
            static function (FinisherContext $context) use (&$finisherRan, &$finisherRequest): void {
                $finisherRan = true;
                $finisherRequest = $context->getRequest();
            },
        );

        $runtime = $form->bind($request);
        self::assertNull($runtime->getCurrentPage());
        $runtime->render();

        self::assertTrue($finisherRan);
        self::assertNull($runtime->getFormState()?->getFormValue($element->getIdentifier()));
        self::assertInstanceOf(Request::class, $finisherRequest);
        self::assertStringNotContainsString($solution, serialize($finisherRequest->getArguments()));
        self::assertStringNotContainsString($solution, serialize($finisherRequest->getParsedBody()));
    }

    #[Test]
    public function multiPageVerificationProofCanRunFinishersOnlyOnce(): void
    {
        $solution = 'accepted-multi-page-solution';
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRuns = 0;
        $finisher = static function () use (&$finisherRuns): void {
            $finisherRuns++;
        };

        $firstRequest = $this->submissionRequest($solution);
        [$firstForm] = $this->multiPageSubmissionForm($firstRequest, $finisher);
        $firstRuntime = $firstForm->bind($firstRequest);
        self::assertSame('page-2', $firstRuntime->getCurrentPage()?->getIdentifier());
        $firstRuntime->getFormState()?->setLastDisplayedPageIndex(1);
        $finalRequest = $this->finalSubmissionRequest($firstRuntime);

        [$finalForm] = $this->multiPageSubmissionForm($finalRequest, $finisher);
        $finalRuntime = $finalForm->bind($finalRequest);
        self::assertNull($finalRuntime->getCurrentPage());
        $finalRuntime->render();
        self::assertSame(1, $finisherRuns);

        [$replayedForm] = $this->multiPageSubmissionForm($finalRequest, $finisher);
        $replayedRuntime = $replayedForm->bind($finalRequest);
        self::assertNull($replayedRuntime->getCurrentPage());
        $replayedRuntime->render();
        self::assertSame(1, $finisherRuns);
    }

    #[Test]
    public function laterPageCannotReintroduceCaptchaSolutionToFinishers(): void
    {
        $solution = 'accepted-before-later-injection';
        $injectedSolution = 'later-injected-captcha-solution';
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRequest = null;
        $finisher = static function (FinisherContext $context) use (&$finisherRequest): void {
            $finisherRequest = $context->getRequest();
        };

        $firstRequest = $this->submissionRequest($solution);
        [$firstForm] = $this->multiPageSubmissionForm($firstRequest, $finisher);
        $firstRuntime = $firstForm->bind($firstRequest);
        self::assertSame('page-2', $firstRuntime->getCurrentPage()?->getIdentifier());
        $firstRuntime->getFormState()?->setLastDisplayedPageIndex(1);
        $finalRequest = $this->continuedSubmissionRequest(
            $firstRuntime,
            2,
            injectedCaptchaSolution: $injectedSolution,
        );

        [$finalForm] = $this->multiPageSubmissionForm($finalRequest, $finisher);
        $finalRuntime = $finalForm->bind($finalRequest);
        $finalRuntime->render();

        self::assertInstanceOf(Request::class, $finisherRequest);
        self::assertStringNotContainsString($injectedSolution, serialize($finisherRequest->getArguments()));
        self::assertStringNotContainsString($injectedSolution, serialize($finisherRequest->getParsedBody()));
        self::assertStringNotContainsString($injectedSolution, (string)$finisherRequest->getBody());
        $contentObject = $finisherRequest->getAttribute('currentContentObject');
        self::assertInstanceOf(ContentObjectRenderer::class, $contentObject);
        self::assertStringNotContainsString(
            $injectedSolution,
            serialize($contentObject->getRequest()->getParsedBody()),
        );
    }

    #[Test]
    public function rootVariantCannotRemoveTheMandatoryGuardFinisher(): void
    {
        $solution = 'accepted-before-variant';
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRuns = 0;
        $finisher = static function () use (&$finisherRuns): void {
            $finisherRuns++;
        };

        $firstRequest = $this->submissionRequest($solution);
        [$firstForm] = $this->multiPageSubmissionForm($firstRequest, $finisher);
        $firstRuntime = $firstForm->bind($firstRequest);
        self::assertSame('page-2', $firstRuntime->getCurrentPage()?->getIdentifier());
        $firstRuntime->getFormState()?->setFormValue('captcha', str_repeat('f', 64));
        $firstRuntime->getFormState()?->setLastDisplayedPageIndex(1);
        $finalRequest = $this->finalSubmissionRequest($firstRuntime);

        [$finalForm] = $this->multiPageSubmissionForm($finalRequest, $finisher);
        $finalForm->createVariant([
            'identifier' => 'replace-finishers',
            'condition' => '1 == 1',
            'finishers' => [[
                'identifier' => 'Closure',
                'options' => ['closure' => $finisher],
            ]],
        ]);
        $finalRuntime = $finalForm->bind($finalRequest);
        self::assertNull($finalRuntime->getCurrentPage());

        $finalRuntime->render();

        self::assertSame(0, $finisherRuns);
    }

    #[Test]
    public function redisplayingCaptchaAfterBackNavigationRevokesTheIssuedProof(): void
    {
        $solution = 'accepted-before-back-navigation';
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRuns = 0;
        $finisher = static function () use (&$finisherRuns): void {
            $finisherRuns++;
        };

        $firstRequest = $this->submissionRequest($solution);
        [$firstForm] = $this->multiPageSubmissionForm($firstRequest, $finisher);
        $firstRuntime = $firstForm->bind($firstRequest);
        self::assertSame('page-2', $firstRuntime->getCurrentPage()?->getIdentifier());
        $firstRuntime->getFormState()?->setLastDisplayedPageIndex(1);
        $savedFinalRequest = $this->finalSubmissionRequest($firstRuntime);
        $backRequest = $this->continuedSubmissionRequest($firstRuntime, 0);

        [$backForm, $backElement] = $this->multiPageSubmissionForm($backRequest, $finisher);
        $backRuntime = $backForm->bind($backRequest);
        self::assertSame('page-1', $backRuntime->getCurrentPage()?->getIdentifier());
        $this->get(FormRuntimeAdapter::class)->beforeRendering($backRuntime, $backElement);

        [$savedFinalForm] = $this->multiPageSubmissionForm($savedFinalRequest, $finisher);
        $savedFinalRuntime = $savedFinalForm->bind($savedFinalRequest);
        self::assertNull($savedFinalRuntime->getCurrentPage());
        $savedFinalRuntime->render();

        self::assertSame(0, $finisherRuns);
    }

    #[Test]
    public function navigatingBackPastCaptchaPageRevokesTheIssuedProof(): void
    {
        $solution = 'accepted-before-distant-back-navigation';
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRuns = 0;
        $finisher = static function () use (&$finisherRuns): void {
            $finisherRuns++;
        };

        $captchaRequest = $this->submissionRequest(
            $solution,
            lastDisplayedPageIndex: 1,
            currentPage: 2,
        );
        [$captchaForm] = $this->threePageSubmissionForm($captchaRequest, $finisher);
        $captchaRuntime = $captchaForm->bind($captchaRequest);
        self::assertSame('page-3', $captchaRuntime->getCurrentPage()?->getIdentifier());
        $captchaRuntime->getFormState()?->setLastDisplayedPageIndex(2);
        $savedFinalRequest = $this->continuedSubmissionRequest($captchaRuntime, 3);
        $backRequest = $this->continuedSubmissionRequest($captchaRuntime, 0);

        [$backForm] = $this->threePageSubmissionForm($backRequest, $finisher);
        $backRuntime = $backForm->bind($backRequest);
        self::assertSame('page-1', $backRuntime->getCurrentPage()?->getIdentifier());

        [$savedFinalForm] = $this->threePageSubmissionForm($savedFinalRequest, $finisher);
        $savedFinalRuntime = $savedFinalForm->bind($savedFinalRequest);
        $savedFinalRuntime->render();

        self::assertSame(0, $finisherRuns);
    }

    #[Test]
    public function laterPageValidationFailureRevokesTheIssuedProof(): void
    {
        $solution = 'accepted-before-later-validation-failure';
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRuns = 0;
        $finisher = static function () use (&$finisherRuns): void {
            $finisherRuns++;
        };

        $captchaRequest = $this->submissionRequest(
            $solution,
            lastDisplayedPageIndex: 1,
            currentPage: 2,
        );
        [$captchaForm] = $this->threePageSubmissionForm($captchaRequest, $finisher);
        $captchaRuntime = $captchaForm->bind($captchaRequest);
        self::assertSame('page-3', $captchaRuntime->getCurrentPage()?->getIdentifier());
        $captchaRuntime->getFormState()?->setLastDisplayedPageIndex(2);
        $savedFinalRequest = $this->continuedSubmissionRequest($captchaRuntime, 3);
        $invalidFinalRequest = $this->continuedSubmissionRequest($captchaRuntime, 3, name: '');

        [$invalidFinalForm] = $this->threePageSubmissionForm($invalidFinalRequest, $finisher);
        $invalidFinalRuntime = $invalidFinalForm->bind($invalidFinalRequest);
        self::assertSame('page-3', $invalidFinalRuntime->getCurrentPage()?->getIdentifier());
        $this->get(FormRuntimeAdapter::class)->beforeRendering(
            $invalidFinalRuntime,
            $invalidFinalRuntime->getFormDefinition(),
        );

        [$savedFinalForm] = $this->threePageSubmissionForm($savedFinalRequest, $finisher);
        $savedFinalRuntime = $savedFinalForm->bind($savedFinalRequest);
        $savedFinalRuntime->render();

        self::assertSame(0, $finisherRuns);
    }

    #[Test]
    public function disablingIntegrationRevokesProofBeforeAllowingFinishers(): void
    {
        $solution = 'accepted-before-disable';
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())
            ->method('verify')
            ->with($solution)
            ->willReturn(VerificationResult::accepted(0, null, 1, 5));
        $this->replaceVerifier($verifier);
        $finisherRuns = 0;
        $finisher = static function () use (&$finisherRuns): void {
            $finisherRuns++;
        };

        $firstRequest = $this->submissionRequest($solution);
        [$firstForm] = $this->multiPageSubmissionForm($firstRequest, $finisher);
        $firstRuntime = $firstForm->bind($firstRequest);
        self::assertSame('page-2', $firstRuntime->getCurrentPage()?->getIdentifier());
        $firstRuntime->getFormState()?->setLastDisplayedPageIndex(1);
        $enabledReplayRequest = $this->finalSubmissionRequest($firstRuntime);
        $disabledFinalRequest = $this->continuedSubmissionRequest(
            $firstRuntime,
            2,
            formFrameworkEnabled: false,
        );

        [$disabledForm] = $this->multiPageSubmissionForm($disabledFinalRequest, $finisher);
        $disabledRuntime = $disabledForm->bind($disabledFinalRequest);
        $disabledRuntime->render();
        self::assertSame(1, $finisherRuns);

        [$replayedForm] = $this->multiPageSubmissionForm($enabledReplayRequest, $finisher);
        $replayedRuntime = $replayedForm->bind($enabledReplayRequest);
        $replayedRuntime->render();

        self::assertSame(1, $finisherRuns);
    }

    /**
     * @return array{FormDefinition, FormElementInterface}
     */
    private function formAndElement(?\Closure $configurePrototype = null, int $captchaPageIndex = 0): array
    {
        $settings = [];
        if (version_compare((new Typo3Version())->getVersion(), '14.2.0', '<')) {
            $settings['yamlConfigurations'] = [
                10 => 'EXT:form/Configuration/Yaml/FormSetup.yaml',
                1774639825 => 'EXT:private_captcha/Configuration/Form/PrivateCaptcha/config.yaml',
                1774639826 => 'EXT:private_captcha/Configuration/Form/PrivateCaptcha/LegacyEditor.yaml',
            ];
        }
        /** @var array<string, mixed> $configuration */
        $configuration = $this->get(ConfigurationManagerInterface::class)->getYamlConfiguration($settings, false);
        $prototypes = $configuration['prototypes'] ?? null;
        self::assertIsArray($prototypes);
        $prototype = $prototypes['standard'] ?? null;
        self::assertIsArray($prototype);
        if ($configurePrototype !== null) {
            $prototype = $configurePrototype($prototype);
            self::assertIsArray($prototype);
        }
        $form = new FormDefinition('contact', $prototype, persistenceIdentifier: 'test:contact');
        $form->setRenderingOption('honeypot', [
            'enable' => false,
            'formElementToUse' => 'Honeypot',
        ]);
        for ($pageIndex = 0; $pageIndex < $captchaPageIndex; $pageIndex++) {
            $page = $form->createPage('page-' . ($pageIndex + 1));
            $page->createElement('page-' . ($pageIndex + 1) . '-value', 'Text');
        }
        $page = $form->createPage('page-' . ($captchaPageIndex + 1));
        $element = $page->createElement('captcha', 'PrivateCaptcha');

        return [$form, $element];
    }

    /**
     * @return array{FormDefinition, FormElementInterface}
     */
    private function submissionForm(
        Request $request,
        \Closure $finisherClosure,
        int $captchaPageIndex = 0,
    ): array {
        [$form, $element] = $this->formAndElement(captchaPageIndex: $captchaPageIndex);
        $form->getProcessingRule($element->getIdentifier())->removeAllValidators();
        $form->setRequest($request);
        self::assertInstanceOf(AbstractRenderable::class, $element);
        $element->setRequest($request);
        $this->installMandatoryValidator($form, $element);

        $finisher = new ClosureFinisher();
        $finisher->setFinisherIdentifier('Closure');
        $finisher->setOptions(['closure' => $finisherClosure]);
        $form->addFinisher($finisher);

        return [$form, $element];
    }

    /**
     * @return array{FormDefinition, FormElementInterface}
     */
    private function multiPageSubmissionForm(
        Request $request,
        \Closure $finisherClosure,
    ): array {
        [$form, $element] = $this->submissionForm($request, $finisherClosure);
        $page = $form->createPage('page-2');
        $page->createElement('name', 'Text');

        return [$form, $element];
    }

    /**
     * @return array{FormDefinition, FormElementInterface}
     */
    private function threePageSubmissionForm(
        Request $request,
        \Closure $finisherClosure,
    ): array {
        [$form, $element] = $this->submissionForm($request, $finisherClosure, captchaPageIndex: 1);
        $page = $form->createPage('page-3');
        $name = $page->createElement('name', 'Text');
        self::assertInstanceOf(AbstractRenderable::class, $name);
        $name->createValidator('NotEmpty');

        return [$form, $element];
    }

    private function request(bool $formFrameworkEnabled): Request
    {
        $site = new Site('site-a', 1, [
            'base' => 'https://site-a.test/',
            'privateCaptcha' => [
                'apiKey' => bin2hex(random_bytes(16)),
                'sitekey' => 'site-property',
                'formFrameworkEnabled' => $formFrameworkEnabled,
            ],
        ]);
        $extbase = (new ExtbaseRequestParameters())
            ->setControllerExtensionName('Form')
            ->setPluginName('Formframework')
            ->setArguments([]);
        $serverRequest = (new ServerRequest('https://site-a.test/contact', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('extbase', $extbase)
            ->withAttribute('site', $site);

        return new Request($serverRequest);
    }

    private function submissionRequest(
        string $solution,
        bool $formFrameworkEnabled = true,
        string $sitekey = 'site-property',
        string $renderedSitekey = 'site-property',
        int $lastDisplayedPageIndex = 0,
        int $currentPage = 1,
        bool $includeSite = true,
        ?string $duplicateCaptchaSolution = null,
    ): Request {
        $state = new FormState();
        $state->setLastDisplayedPageIndex($lastDisplayedPageIndex);
        $this->get(FormRenderedSitekeyStore::class)->remember(
            $state,
            'site-a',
            'test:contact',
            'contact',
            'captcha',
            $renderedSitekey,
        );
        $serializedState = base64_encode(serialize($state));
        $hashService = $this->get(HashService::class);
        if (version_compare((new Typo3Version())->getVersion(), '14.0.0', '<')) {
            $stateToken = $hashService->appendHmac($serializedState, HashScope::FormState->prefix());
        } else {
            $hashAlgoClass = 'TYPO3\\CMS\\Core\\Crypto\\HashAlgo';
            $stateToken = (new \ReflectionMethod($hashService, 'appendHmac'))->invoke(
                $hashService,
                $serializedState,
                HashScope::FormState->prefix(),
                constant($hashAlgoClass . '::SHA3_256'),
            );
        }
        self::assertIsString($stateToken);
        $formArguments = [
            'contact' => [
                'captcha' => $solution,
                '__state' => $stateToken,
                '__currentPage' => $currentPage,
            ],
        ];
        if ($duplicateCaptchaSolution !== null) {
            $formArguments['contact']['contact'] = ['captcha' => $duplicateCaptchaSolution];
        }
        $extbase = (new ExtbaseRequestParameters())
            ->setControllerExtensionName('Form')
            ->setPluginName('Formframework')
            ->setArguments($formArguments);
        $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObject->setUserObjectType(ContentObjectRenderer::OBJECTTYPE_USER_INT);
        $rawBody = http_build_query(['tx_form_formframework' => $formArguments]);
        $body = new Stream('php://temp', 'rw');
        $body->write($rawBody);
        $serverRequest = (new ServerRequest(
            'https://site-a.test/contact',
            'POST',
            $body,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => (string)strlen($rawBody),
            ],
        ))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('extbase', $extbase)
            ->withParsedBody(['tx_form_formframework' => $formArguments]);
        if ($includeSite) {
            $serverRequest = $serverRequest->withAttribute('site', $this->site($formFrameworkEnabled, $sitekey));
        }
        $contentObject->setRequest($serverRequest);
        $serverRequest = $serverRequest->withAttribute('currentContentObject', $contentObject);

        return new Request($serverRequest);
    }

    private function finalSubmissionRequest(FormRuntime $runtime): Request
    {
        return $this->continuedSubmissionRequest($runtime, 2);
    }

    private function continuedSubmissionRequest(
        FormRuntime $runtime,
        int $currentPage,
        bool $formFrameworkEnabled = true,
        string $name = 'Ada',
        ?string $injectedCaptchaSolution = null,
    ): Request {
        $formState = $runtime->getFormState();
        $formSession = $runtime->getFormSession();
        self::assertNotNull($formState);
        self::assertNotNull($formSession);
        $formArguments = [
            'contact' => [
                'name' => $name,
                '__state' => $this->authenticatedFormState($formState),
                '__session' => $formSession->getAuthenticatedIdentifier(),
                '__currentPage' => $currentPage,
            ],
        ];
        if ($injectedCaptchaSolution !== null) {
            $formArguments['contact']['captcha'] = $injectedCaptchaSolution;
        }
        $extbase = (new ExtbaseRequestParameters())
            ->setControllerExtensionName('Form')
            ->setPluginName('Formframework')
            ->setArguments($formArguments);
        $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObject->setUserObjectType(ContentObjectRenderer::OBJECTTYPE_USER_INT);
        $rawBody = http_build_query(['tx_form_formframework' => $formArguments]);
        $body = new Stream('php://temp', 'rw');
        $body->write($rawBody);
        $serverRequest = (new ServerRequest(
            'https://site-a.test/contact',
            'POST',
            $body,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => (string)strlen($rawBody),
            ],
        ))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('extbase', $extbase)
            ->withAttribute('site', $this->site($formFrameworkEnabled))
            ->withParsedBody(['tx_form_formframework' => $formArguments]);
        $contentObject->setRequest($serverRequest);
        $serverRequest = $serverRequest->withAttribute('currentContentObject', $contentObject);

        return new Request($serverRequest);
    }

    private function runtime(Request $request): FormRuntime
    {
        $runtime = self::createStub(FormRuntime::class);
        $runtime->method('getRequest')->willReturn($request);

        return $runtime;
    }

    private function instantiateEvent(string $eventClass, FormDefinition $form): object
    {
        if (!class_exists($eventClass)) {
            self::fail('Expected TYPO3 Form event class is unavailable.');
        }

        return new $eventClass($form);
    }

    private function installMandatoryValidator(FormDefinition $form, FormElementInterface $element): void
    {
        if (version_compare((new Typo3Version())->getVersion(), '14.0.0', '<')) {
            $adapterClass = $this->formHook('afterBuildingFinished');
            GeneralUtility::makeInstance($adapterClass)->afterBuildingFinished($element);
            return;
        }

        $eventClass = 'TYPO3\\CMS\\Form\\Event\\' . 'AfterFormIsBuiltEvent';
        $event = $this->instantiateEvent($eventClass, $form);
        $this->get(EventDispatcherInterface::class)->dispatch($event);
    }

    private function replaceVerifier(CaptchaVerifierInterface $verifier): void
    {
        $container = self::getContainer();
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Container::class, $container);
        $container->set(CaptchaVerifierInterface::class, $verifier);
    }

    private function authenticatedFormState(FormState $state): string
    {
        $serializedState = base64_encode(serialize($state));
        $hashService = $this->get(HashService::class);
        if (version_compare((new Typo3Version())->getVersion(), '14.0.0', '<')) {
            return $hashService->appendHmac($serializedState, HashScope::FormState->prefix());
        }

        $hashAlgoClass = 'TYPO3\\CMS\\Core\\Crypto\\HashAlgo';
        $stateToken = (new \ReflectionMethod($hashService, 'appendHmac'))->invoke(
            $hashService,
            $serializedState,
            HashScope::FormState->prefix(),
            constant($hashAlgoClass . '::SHA3_256'),
        );
        self::assertIsString($stateToken);
        return $stateToken;
    }

    /**
     * @return class-string<FormRuntimeAdapter>
     */
    private function formHook(string $hook): string
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        self::assertIsArray($typo3Configuration);
        $scOptions = $typo3Configuration['SC_OPTIONS'] ?? null;
        self::assertIsArray($scOptions);
        $formOptions = $scOptions['ext/form'] ?? null;
        self::assertIsArray($formOptions);
        $hooks = $formOptions[$hook] ?? null;
        self::assertIsArray($hooks);
        $adapterClass = $hooks['private-captcha'] ?? null;
        self::assertSame(FormRuntimeAdapter::class, $adapterClass);

        return FormRuntimeAdapter::class;
    }

    private function site(bool $formFrameworkEnabled, string $sitekey = 'site-property'): Site
    {
        return new Site('site-a', 1, [
            'base' => 'https://site-a.test/',
            'privateCaptcha' => [
                'apiKey' => bin2hex(random_bytes(16)),
                'sitekey' => $sitekey,
                'formFrameworkEnabled' => $formFrameworkEnabled,
            ],
        ]);
    }
}
