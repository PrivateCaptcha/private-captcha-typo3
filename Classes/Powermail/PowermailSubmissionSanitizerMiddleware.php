<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Powermail;

use Doctrine\DBAL\ArrayParameterType;
use In2code\Powermail\Utility\ConfigurationUtility;
use PrivateCaptcha\Typo3\Service\SolutionVault;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * Removes raw solutions before Powermail's spam shield can log request data.
 *
 * @internal
 */
final readonly class PowermailSubmissionSanitizerMiddleware implements MiddlewareInterface
{
    private const PLUGIN_ARGUMENT = 'tx_powermail_pi1';

    private const FORM_TABLE = 'tx_powermail_domain_model_form';

    private const PAGE_TABLE = 'tx_powermail_domain_model_page';

    private const FIELD_TABLE = 'tx_powermail_domain_model_field';

    private const MAX_SUBMITTED_FIELDS = 1024;

    public function __construct(
        private ConnectionPool $connectionPool,
        private SolutionVault $solutionVault,
    ) {}

    public function process(
        #[\SensitiveParameter]
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $parsedBody = $request->getParsedBody();
        $body = is_array($parsedBody) ? $parsedBody : [];
        $query = $request->getQueryParams();
        $pluginArguments = $body[self::PLUGIN_ARGUMENT] ?? null;
        $fields = is_array($pluginArguments) ? ($pluginArguments['field'] ?? null) : null;
        $mail = is_array($pluginArguments) ? ($pluginArguments['mail'] ?? null) : null;
        $bodyFormUid = is_array($mail) ? $this->positiveInteger($mail['form'] ?? null) : 0;
        $queryPluginArguments = $query[self::PLUGIN_ARGUMENT] ?? null;
        $queryFields = is_array($queryPluginArguments) ? ($queryPluginArguments['field'] ?? null) : null;
        $queryMail = is_array($queryPluginArguments) ? ($queryPluginArguments['mail'] ?? null) : null;
        $queryFormUid = is_array($queryMail) ? $this->positiveInteger($queryMail['form'] ?? null) : 0;
        $formUid = $bodyFormUid > 0 ? $bodyFormUid : $queryFormUid;
        $formUids = array_values(array_unique(array_filter(
            [$formUid, $queryFormUid],
            static fn(int $uid): bool => $uid > 0,
        )));
        if ($formUids === []) {
            if (is_array($fields) || is_array($queryFields)) {
                $this->clearSubmittedFieldsFromGlobals([
                    ...array_keys(is_array($fields) ? $fields : []),
                    ...array_keys(is_array($queryFields) ? $queryFields : []),
                ]);
                return new Response(statusCode: 400);
            }
            return $handler->handle($request);
        }

        try {
            $captchaFieldsByForm = [];
            foreach ($formUids as $candidateFormUid) {
                $captchaFieldsByForm[$candidateFormUid] = $this->privateCaptchaFields($candidateFormUid);
            }
        } catch (\Throwable) {
            $this->clearSubmittedFieldsFromGlobals([
                ...array_keys(is_array($fields) ? $fields : []),
                ...array_keys(is_array($queryFields) ? $queryFields : []),
            ]);
            return new Response(statusCode: 500);
        }
        $markers = $captchaFieldsByForm[$formUid]['markers'] ?? [];
        $pageUids = $captchaFieldsByForm[$formUid]['pageUids'] ?? [];
        $allMarkers = array_values(array_unique(array_merge(...array_column($captchaFieldsByForm, 'markers'))));
        $allPageUids = array_values(array_unique(array_merge(...array_column($captchaFieldsByForm, 'pageUids'))));
        try {
            $queryHasCaptcha = is_array($queryFields)
                && (array_intersect($allMarkers, array_keys($queryFields)) !== []
                    || $this->hasCollationAlias($queryFields, $allMarkers, $allPageUids));
        } catch (\Throwable) {
            $this->clearSubmittedFieldsFromGlobals(array_keys(is_array($queryFields) ? $queryFields : []));
            return new Response(statusCode: 500);
        }
        if ($queryHasCaptcha) {
            $this->clearSubmittedFieldsFromGlobals(array_keys($queryFields));
            return new Response(statusCode: 400);
        }
        if (strtoupper($request->getMethod()) !== 'POST' || !is_array($parsedBody) || !is_array($fields)) {
            return $handler->handle($request);
        }
        try {
            $hasBodyAlias = $this->hasCollationAlias($fields, $markers, $pageUids);
        } catch (\Throwable) {
            $this->clearSubmittedFieldsFromGlobals(array_keys($fields));
            return new Response(statusCode: 500);
        }
        if ($hasBodyAlias) {
            $this->clearSubmittedFieldsFromGlobals(array_keys($fields));
            return new Response(statusCode: 400);
        }

        $capturedNonces = [];
        $bodySanitized = false;
        foreach ($markers as $marker) {
            if (!array_key_exists($marker, $fields)) {
                continue;
            }
            $bodySanitized = true;
            try {
                $nonce = $this->solutionVault->capture($fields[$marker], [
                    'formUid' => $formUid,
                    'marker' => $marker,
                ]);
            } catch (\Throwable) {
                $this->clearSubmittedFieldsFromGlobals(array_keys($fields));
                $this->discard($capturedNonces);
                return new Response(statusCode: 500);
            }
            $capturedNonces[] = $nonce;
            $fields[$marker] = $nonce;
            $this->replaceGlobalValue($_POST, $marker, $nonce);
            $this->replaceGlobalValue($_REQUEST, $marker, $nonce);
        }
        if (!$bodySanitized) {
            return $handler->handle($request);
        }
        $pluginArguments['field'] = $fields;
        $body[self::PLUGIN_ARGUMENT] = $pluginArguments;
        $request = $request
            ->withParsedBody($body)
            ->withBody(new Stream('php://temp', 'rw'))
            ->withoutHeader('Content-Length');

        try {
            return $handler->handle($request);
        } finally {
            $this->discard($capturedNonces);
        }
    }

    /**
     * @return array{markers: list<string>, pageUids: list<int>}
     */
    private function privateCaptchaFields(int $formUid): array
    {
        $pageQuery = $this->connectionPool->getQueryBuilderForTable(self::PAGE_TABLE);
        $pageUids = $pageQuery
            ->select('uid')
            ->from(self::PAGE_TABLE)
            ->where($pageQuery->expr()->eq(
                'form',
                $pageQuery->createNamedParameter($formUid),
            ))
            ->executeQuery()
            ->fetchFirstColumn();
        $pageUids = array_map(fn(mixed $uid): int => $this->positiveInteger($uid), $pageUids);

        if (ConfigurationUtility::isReplaceIrreWithElementBrowserActive()) {
            $formQuery = $this->connectionPool->getQueryBuilderForTable(self::FORM_TABLE);
            $configuredPages = $formQuery
                ->select('pages')
                ->from(self::FORM_TABLE)
                ->where($formQuery->expr()->eq(
                    'uid',
                    $formQuery->createNamedParameter($formUid),
                ))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            if (is_string($configuredPages)) {
                foreach (explode(',', $configuredPages) as $pageUid) {
                    $pageUid = $this->positiveInteger(trim($pageUid));
                    if ($pageUid > 0) {
                        $pageUids[] = $pageUid;
                    }
                }
            }
        }
        $pageUids = array_values(array_unique(array_filter($pageUids, static fn(int $uid): bool => $uid > 0)));
        if ($pageUids === []) {
            return ['markers' => [], 'pageUids' => []];
        }

        $fieldQuery = $this->connectionPool->getQueryBuilderForTable(self::FIELD_TABLE);
        $markers = $fieldQuery
            ->select('marker')
            ->from(self::FIELD_TABLE)
            ->where(
                $fieldQuery->expr()->eq(
                    'type',
                    $fieldQuery->createNamedParameter('privateCaptcha'),
                ),
                $fieldQuery->expr()->in(
                    'page',
                    $fieldQuery->createNamedParameter($pageUids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return [
            'markers' => array_values(array_unique(array_filter(
                $markers,
                static fn(mixed $marker): bool => is_string($marker) && $marker !== '',
            ))),
            'pageUids' => $pageUids,
        ];
    }

    /**
     * @param array<array-key, mixed> $global
     */
    private function replaceGlobalValue(array &$global, string $marker, string $nonce): void
    {
        $pluginArguments = $global[self::PLUGIN_ARGUMENT] ?? null;
        $fields = is_array($pluginArguments) ? ($pluginArguments['field'] ?? null) : null;
        if (!is_array($fields) || !array_key_exists($marker, $fields)) {
            return;
        }
        $fields[$marker] = $nonce;
        $pluginArguments['field'] = $fields;
        $global[self::PLUGIN_ARGUMENT] = $pluginArguments;
    }

    /**
     * @param list<int|string> $markers
     */
    private function clearSubmittedFieldsFromGlobals(array $markers): void
    {
        foreach ($markers as $marker) {
            if (is_string($marker)) {
                $this->replaceGlobalValue($_GET, $marker, '');
                $this->replaceGlobalValue($_POST, $marker, '');
                $this->replaceGlobalValue($_REQUEST, $marker, '');
            }
        }
    }

    /**
     * @param list<string> $nonces
     */
    private function discard(array $nonces): void
    {
        foreach ($nonces as $nonce) {
            $this->solutionVault->discard($nonce);
        }
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param list<string> $markers
     * @param list<int> $pageUids
     */
    private function hasCollationAlias(array $fields, array $markers, array $pageUids): bool
    {
        if (count($fields) > self::MAX_SUBMITTED_FIELDS) {
            return true;
        }
        $submittedKeys = array_values(array_filter(array_keys($fields), 'is_string'));
        $unknownKeys = array_values(array_diff($submittedKeys, $markers));
        foreach ($unknownKeys as $unknownKey) {
            foreach ($markers as $marker) {
                if (strcasecmp(rtrim($unknownKey), rtrim($marker)) === 0) {
                    return true;
                }
            }
        }
        if ($unknownKeys === [] || $pageUids === []) {
            return false;
        }
        $fieldQuery = $this->connectionPool->getQueryBuilderForTable(self::FIELD_TABLE);

        return $fieldQuery
            ->select('uid')
            ->from(self::FIELD_TABLE)
            ->where(
                $fieldQuery->expr()->eq(
                    'type',
                    $fieldQuery->createNamedParameter('privateCaptcha'),
                ),
                $fieldQuery->expr()->in(
                    'page',
                    $fieldQuery->createNamedParameter($pageUids, ArrayParameterType::INTEGER),
                ),
                $fieldQuery->expr()->in(
                    'marker',
                    $fieldQuery->createNamedParameter($unknownKeys, ArrayParameterType::STRING),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne() !== false;
    }

    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
            return 0;
        }

        return max(0, (int)$value);
    }
}
