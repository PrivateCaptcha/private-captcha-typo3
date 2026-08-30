<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Powermail;

use PrivateCaptcha\Typo3\Form\FormProofBinding;
use PrivateCaptcha\Typo3\Form\FormProofStore;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * @internal
 */
final class ConfirmationProofStore
{
    private const SESSION_KEY = 'privateCaptchaPowermailConfirmationBinding';

    private const PURPOSE = 'powermail-confirmation-v1';

    public function __construct(
        private readonly FormProofStore $proofStore,
    ) {}

    public function issue(
        ServerRequestInterface $request,
        Site $site,
        int $formUid,
        int $fieldUid,
        string $marker,
        string $sitekey,
        int $contentUid,
        string $businessDigest,
    ): ?string {
        if ($formUid < 1
            || $fieldUid < 1
            || $contentUid < 1
            || $marker === ''
            || $sitekey === ''
            || !$this->isNonce($businessDigest)
        ) {
            return null;
        }
        $binding = $this->binding(
            $request,
            $site,
            $formUid,
            $fieldUid,
            $marker,
            $sitekey,
            $contentUid,
            $businessDigest,
            true,
        );
        if (!$binding instanceof FormProofBinding) {
            return null;
        }
        $nonce = bin2hex(random_bytes(32));
        $this->proofStore->issue($nonce, $binding);

        return $nonce . $businessDigest;
    }

    public function consume(
        ServerRequestInterface $request,
        Site $site,
        string $proof,
        int $formUid,
        int $fieldUid,
        string $marker,
        string $sitekey,
        int $contentUid,
        string $businessDigest,
    ): bool {
        $proofParts = $this->proofParts($proof);
        if ($proofParts === null || !hash_equals($proofParts['businessDigest'], $businessDigest)) {
            return false;
        }
        $binding = $this->binding(
            $request,
            $site,
            $formUid,
            $fieldUid,
            $marker,
            $sitekey,
            $contentUid,
            $businessDigest,
            false,
        );

        return $binding instanceof FormProofBinding && $this->proofStore->consume($proofParts['nonce'], $binding);
    }

    public function consumeOnBack(
        ServerRequestInterface $request,
        Site $site,
        string $proof,
        int $formUid,
        int $fieldUid,
        string $marker,
        string $sitekey,
        int $contentUid,
    ): bool {
        $proofParts = $this->proofParts($proof);
        return $proofParts !== null && $this->consume(
            $request,
            $site,
            $proof,
            $formUid,
            $fieldUid,
            $marker,
            $sitekey,
            $contentUid,
            $proofParts['businessDigest'],
        );
    }

    public function revoke(string $proof): void
    {
        $proofParts = $this->proofParts($proof);
        if ($proofParts !== null) {
            $this->proofStore->revoke($proofParts['nonce']);
        }
    }

    private function binding(
        ServerRequestInterface $request,
        Site $site,
        int $formUid,
        int $fieldUid,
        string $marker,
        string $sitekey,
        int $contentUid,
        string $businessDigest,
        bool $createSessionBinding,
    ): ?FormProofBinding {
        if ($contentUid < 1) {
            return null;
        }
        $frontendUser = $request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            return null;
        }
        $sessionIdentifier = $frontendUser->getSession()->getIdentifier();
        $sessionPin = $frontendUser->getKey('ses', self::SESSION_KEY);
        if ($createSessionBinding && (!is_string($sessionPin) || !$this->isNonce($sessionPin))) {
            $sessionPin = bin2hex(random_bytes(32));
            $frontendUser->setKey('ses', self::SESSION_KEY, $sessionPin);
        }
        if ($sessionIdentifier === '' || !is_string($sessionPin) || !$this->isNonce($sessionPin)) {
            return null;
        }

        return new FormProofBinding(
            hash('sha256', $sessionIdentifier . "\0" . $sessionPin),
            $site->getIdentifier(),
            'powermail:' . $formUid,
            implode(':', ['field', $fieldUid, $marker, 'content', $contentUid]),
            $sitekey,
            self::PURPOSE . ':' . $businessDigest,
        );
    }

    /**
     * @return array{nonce: string, businessDigest: string}|null
     */
    private function proofParts(string $proof): ?array
    {
        if (preg_match('/\A[a-f0-9]{128}\z/D', $proof) !== 1) {
            return null;
        }

        return [
            'nonce' => substr($proof, 0, 64),
            'businessDigest' => substr($proof, 64, 64),
        ];
    }

    private function isNonce(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
