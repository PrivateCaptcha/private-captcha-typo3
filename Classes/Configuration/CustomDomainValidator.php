<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Configuration;

/**
 * @internal
 */
final class CustomDomainValidator
{
    private const MAX_ROOT_LENGTH = 249;

    // Update from https://data.iana.org/TLD/tlds-alpha-by-domain.txt before releases.
    private const IANA_TOP_LEVEL_DOMAINS_FILE = __DIR__ . '/../../Resources/Private/Iana/tlds-alpha-by-domain.txt';

    private const PRIVATE_SUFFIXES = [
        'alt',
        'arpa',
        'corp',
        'example',
        'example.com',
        'example.net',
        'example.org',
        'home',
        'internal',
        'invalid',
        'lan',
        'local',
        'localhost',
        'localdomain',
        'mail',
        'onion',
        'test',
    ];

    /** @var array<string, true>|null */
    private static ?array $publicTopLevelDomains = null;

    public function validate(string $domain): string
    {
        $controlCharacterMatch = preg_match('/\p{Cc}/u', $domain);
        if ($controlCharacterMatch === false || $controlCharacterMatch === 1) {
            throw new \InvalidArgumentException('Custom root domain must be valid text without control characters.');
        }

        $domain = strtolower(trim($domain, ' '));
        if ($domain === '') {
            return '';
        }

        if (
            strlen($domain) > self::MAX_ROOT_LENGTH
            || filter_var($domain, FILTER_VALIDATE_IP) !== false
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || str_ends_with($domain, '.')
        ) {
            throw new \InvalidArgumentException('Custom root domain must be a valid public DNS root.');
        }

        $labels = explode('.', $domain);
        $topLevelLabel = $labels[array_key_last($labels)];
        if (count($labels) < 2 || preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D', $topLevelLabel) !== 1) {
            throw new \InvalidArgumentException('Custom root domain must be a valid public DNS root.');
        }

        $idnaInfo = [];
        $canonicalDomain = idn_to_ascii(
            $domain,
            IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46,
            $idnaInfo,
        );
        $idnaErrors = is_array($idnaInfo) ? ($idnaInfo['errors'] ?? 1) : 1;
        if ($canonicalDomain === false || $idnaErrors !== 0 || $canonicalDomain !== $domain) {
            throw new \InvalidArgumentException('Custom root domain must use valid canonical DNS labels.');
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                throw new \InvalidArgumentException('Custom root domain must not use a private or reserved name.');
            }
        }

        if (!isset($this->publicTopLevelDomains()[$topLevelLabel])) {
            throw new \InvalidArgumentException('Custom root domain must use a delegated public top-level domain.');
        }

        return $domain;
    }

    /**
     * @return array<string, true>
     */
    private function publicTopLevelDomains(): array
    {
        if (self::$publicTopLevelDomains !== null) {
            return self::$publicTopLevelDomains;
        }

        $lines = file(self::IANA_TOP_LEVEL_DOMAINS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \LogicException('The IANA top-level domain data could not be loaded.');
        }

        $topLevelDomains = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '#')) {
                $topLevelDomains[strtolower($line)] = true;
            }
        }

        self::$publicTopLevelDomains = $topLevelDomains;

        return $topLevelDomains;
    }
}
