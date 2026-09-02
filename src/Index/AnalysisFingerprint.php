<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use Composer\InstalledVersions;

final readonly class AnalysisFingerprint
{
    public string $phpStanReference;

    public function __construct(
        public string $phpStanVersion,
        public string $phpStanConfigSha256,
        public string $composerLockSha256,
        public string $sourceDigest,
        ?string $phpStanReference = null,
        public ?SemanticScope $semanticScope = null,
    ) {
        $this->phpStanReference = $phpStanReference ?? self::runtimePhpStanReference($phpStanVersion);
    }

    /**
     * @return array{phpstan_version: string, phpstan_reference: string, phpstan_config_sha256: string, composer_lock_sha256: string, source_digest: string, semantic_scope?: array{paths: list<string>, excludes: list<string>, scan_directories: list<string>, identity_sha256: string}}
     */
    public function toArray(): array
    {
        $data = [
            'phpstan_version' => $this->phpStanVersion,
            'phpstan_reference' => $this->phpStanReference,
            'phpstan_config_sha256' => $this->phpStanConfigSha256,
            'composer_lock_sha256' => $this->composerLockSha256,
            'source_digest' => $this->sourceDigest,
        ];
        if ($this->semanticScope !== null) {
            $data['semantic_scope'] = $this->semanticScope->toArray();
        }

        return $data;
    }

    /**
     * @param array{phpstan_version?: mixed, phpstan_reference?: mixed, phpstan_config_sha256?: mixed, composer_lock_sha256?: mixed, source_digest?: mixed, semantic_scope?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            phpStanVersion: (string) ($data['phpstan_version'] ?? ''),
            phpStanConfigSha256: (string) ($data['phpstan_config_sha256'] ?? ''),
            composerLockSha256: (string) ($data['composer_lock_sha256'] ?? ''),
            sourceDigest: (string) ($data['source_digest'] ?? ''),
            phpStanReference: array_key_exists('phpstan_reference', $data)
                ? (string) $data['phpstan_reference']
                : 'unknown',
            semanticScope: is_array($data['semantic_scope'] ?? null)
                ? SemanticScope::fromArray($data['semantic_scope'])
                : null,
        );
    }

    private static function runtimePhpStanReference(string $phpStanVersion): string
    {
        if ($phpStanVersion === 'none') {
            return 'none';
        }
        if (!InstalledVersions::isInstalled('phpstan/phpstan')) {
            return 'unknown';
        }

        $reference = InstalledVersions::getReference('phpstan/phpstan');

        return is_string($reference) && $reference !== '' ? $reference : 'unknown';
    }
}
