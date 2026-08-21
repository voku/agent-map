<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use voku\AgentMap\Index\AnalysisFingerprint;

/** Immutable identity of the map and source snapshot used to produce a method rename plan. */
final readonly class MethodRenameProvenance
{
    /** @param array<string, string> $sourceHashes repository-relative path => indexed SHA-256 */
    public function __construct(
        public string $mapDigest,
        public string $backend,
        public array $sourceHashes,
        public ?AnalysisFingerprint $analysisFingerprint,
    ) {
    }

    /** @return array{map_digest: string, backend: string, source_hashes: array<string, string>, analysis_fingerprint: array<string, string>|null} */
    public function toArray(): array
    {
        return [
            'map_digest' => $this->mapDigest,
            'backend' => $this->backend,
            'source_hashes' => $this->sourceHashes,
            'analysis_fingerprint' => $this->analysisFingerprint?->toArray(),
        ];
    }
}
