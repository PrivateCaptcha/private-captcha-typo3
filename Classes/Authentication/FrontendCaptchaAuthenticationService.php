<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Authentication;

use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Authentication\MimicServiceInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * @internal
 */
final class FrontendCaptchaAuthenticationService extends AbstractAuthenticationService implements MimicServiceInterface
{
    public function __construct(
        private readonly ConfigurationResolver $configurationResolver,
        private readonly CaptchaVerifierInterface $captchaVerifier,
    ) {}

    /**
     * @param array<string, mixed> $user
     */
    public function authUser(array $user): int
    {
        return $this->captchaAccepted() ? 100 : 0;
    }

    public function mimicAuthUser(): bool
    {
        return $this->captchaAccepted();
    }

    private function captchaAccepted(): bool
    {
        if ($this->mode !== 'authUserFE' || ($this->login['status'] ?? null) !== 'login') {
            return true;
        }
        $request = $this->authInfo['request'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return false;
        }
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return false;
        }

        try {
            $configuration = $this->configurationResolver->resolveSite($site);
        } catch (\Throwable) {
            return false;
        }
        if (!$configuration->requestedIntegrations->frontendLogin) {
            return true;
        }
        if (!$configuration->integrations->frontendLogin || strtoupper($request->getMethod()) !== 'POST') {
            return false;
        }

        $body = $request->getParsedBody();
        $solution = is_array($body) ? ($body[Client::DEFAULT_FORM_FIELD] ?? null) : null;
        try {
            return $this->captchaVerifier->verify($solution, $configuration)->accepted;
        } catch (\Throwable) {
            return false;
        }
    }
}
