<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class AnalysisFingerprint
{
    public function __construct(
        public string $phpStanVersion,
        public string $phpStanConfigSha256,
        public string $composerLockSha256,
        public string $sourceDigest,
    ) {
    }

    /**
     * @return array{phpstan_version: string, phpstan_config_sha256: string, composer_lock_sha256: string, source_digest: string}
     */
    public function toArray(): array
    {
        return [
            'phpstan_version' => $this->phpStanVersion,
            'phpstan_config_sha256' => $this->phpStanConfigSha256,
            'composer_lock_sha256' => $this->composerLockSha256,
            'source_digest' => $this->sourceDigest,
        ];
    }

    /**
     * @param array{phpstan_version?: mixed, phpstan_config_sha256?: mixed, composer_lock_sha256?: mixed, source_digest?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            phpStanVersion: (string) ($data['phpstan_version'] ?? ''),
            phpStanConfigSha256: (string) ($data['phpstan_config_sha256'] ?? ''),
            composerLockSha256: (string) ($data['composer_lock_sha256'] ?? ''),
            sourceDigest: (string) ($data['source_digest'] ?? ''),
        );
    }
}
