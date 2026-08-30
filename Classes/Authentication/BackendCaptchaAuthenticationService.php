<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Authentication;

use PrivateCaptcha\Client;
use PrivateCaptcha\Typo3\Configuration\ConfigurationResolver;
use PrivateCaptcha\Typo3\Service\CaptchaVerifierInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Authentication\MimicServiceInterface;

/**
 * @internal
 */
final class BackendCaptchaAuthenticationService extends AbstractAuthenticationService implements MimicServiceInterface
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
        if ($this->mode !== 'authUserBE' || ($this->login['status'] ?? null) !== 'login') {
            return true;
        }
        $request = $this->authInfo['request'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return false;
        }

        try {
            $configuration = $this->configurationResolver->resolveBackend();
        } catch (\Throwable) {
            return false;
        }
        if (!$configuration->requestedIntegrations->backendLogin) {
            return true;
        }
        if (!$configuration->integrations->backendLogin || strtoupper($request->getMethod()) !== 'POST') {
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
