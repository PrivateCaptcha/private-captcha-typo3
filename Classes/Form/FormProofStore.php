<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Form;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final readonly class FormProofStore
{
    private const TABLE = 'tx_privatecaptcha_formproof';

    private const LIFETIME_SECONDS = 1800;

    private const PURGE_BATCH_SIZE = 100;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function issue(string $nonce, FormProofBinding $binding): void
    {
        $this->purgeExpiredBatch();
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'nonce_hash' => $this->nonceHash($nonce),
            'binding_hash' => $binding->hash(),
            'expires_at' => time() + self::LIFETIME_SECONDS,
        ], [
            Connection::PARAM_STR,
            Connection::PARAM_STR,
            Connection::PARAM_INT,
        ]);
    }

    public function consume(string $nonce, FormProofBinding $binding): bool
    {
        if (!$this->isNonce($nonce)) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'nonce_hash',
                    $queryBuilder->createNamedParameter($this->nonceHash($nonce)),
                ),
                $queryBuilder->expr()->eq(
                    'binding_hash',
                    $queryBuilder->createNamedParameter($binding->hash()),
                ),
                $queryBuilder->expr()->gte(
                    'expires_at',
                    $queryBuilder->createNamedParameter(time(), Connection::PARAM_INT),
                ),
            )
            ->executeStatement() === 1;
    }

    public function revoke(string $nonce): void
    {
        if (!$this->isNonce($nonce)) {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->eq(
                'nonce_hash',
                $queryBuilder->createNamedParameter($this->nonceHash($nonce)),
            ))
            ->executeStatement();
    }

    private function purgeExpiredBatch(): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $expiredNonceHashes = $queryBuilder
            ->select('nonce_hash')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->lt(
                'expires_at',
                $queryBuilder->createNamedParameter(time(), Connection::PARAM_INT),
            ))
            ->orderBy('expires_at', 'ASC')
            ->setMaxResults(self::PURGE_BATCH_SIZE)
            ->executeQuery()
            ->fetchFirstColumn();
        $expiredNonceHashes = array_values(array_filter($expiredNonceHashes, is_string(...)));
        if ($expiredNonceHashes === []) {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->in(
                'nonce_hash',
                $queryBuilder->createNamedParameter($expiredNonceHashes, Connection::PARAM_STR_ARRAY),
            ))
            ->executeStatement();
    }

    private function nonceHash(string $nonce): string
    {
        if (!$this->isNonce($nonce)) {
            throw new \InvalidArgumentException('Form proof nonce is invalid.');
        }

        return hash('sha256', $nonce);
    }

    private function isNonce(string $nonce): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $nonce) === 1;
    }
}
