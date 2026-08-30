<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\ValueObject;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * @internal
 */
final readonly class SettingsSubmission
{
    private const FIELDS = [
        'sitekey',
        'theme',
        'language',
        'startMode',
        'debug',
        'customStyles',
        'euIsolation',
        'customRootDomain',
        'formFrameworkEnabled',
        'powermailEnabled',
        'frontendLoginEnabled',
        'backendLoginEnabled',
    ];

    private \SensitiveParameterValue $apiKey;

    private \SensitiveParameterValue $site;

    private bool $backend;

    private bool $hasApiKey;

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $input
     */
    private function __construct(
        ?Site $site,
        #[\SensitiveParameter]
        array $input,
    ) {
        $this->site = new \SensitiveParameterValue($site);
        $this->backend = $site === null;
        $this->hasApiKey = array_key_exists('apiKey', $input);
        $this->apiKey = new \SensitiveParameterValue($input['apiKey'] ?? null);
        $this->values = array_intersect_key($input, array_fill_keys(self::FIELDS, true));
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function forSite(
        Site $site,
        #[\SensitiveParameter]
        array $input,
    ): self {
        return new self($site, $input);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function forBackend(#[\SensitiveParameter] array $input): self
    {
        return new self(null, $input);
    }

    public function isBackend(): bool
    {
        return $this->backend;
    }

    public function site(): Site
    {
        $site = $this->site->getValue();
        if (!$site instanceof Site) {
            throw new \LogicException('Backend settings submissions do not have a site.');
        }

        return $site;
    }

    /**
     * @return array<string, mixed>
     */
    public function input(): array
    {
        $input = $this->values;
        if ($this->hasApiKey) {
            $input['apiKey'] = $this->apiKey->getValue();
        }

        return $input;
    }

    /**
     * @return array{scope: string, siteIdentifier?: string}
     */
    public function __debugInfo(): array
    {
        if ($this->backend) {
            return ['scope' => 'backend'];
        }

        return [
            'scope' => 'site',
            'siteIdentifier' => $this->site()->getIdentifier(),
        ];
    }
}
