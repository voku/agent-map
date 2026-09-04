<?php

declare(strict_types=1);

namespace voku\AgentMap\Plan;

use voku\AgentMap\Index\AnalysisFingerprint;

/** Immutable identity of the map and source snapshot used to produce one governed rename plan. */
final readonly class PlanProvenance
{
    public function __construct(
        public string $mapDigest,
        public string $backend,
        public ?AnalysisFingerprint $analysisFingerprint,
    ) {
    }

    /** @return array{map_digest: string, backend: string, analysis_fingerprint: array{phpstan_version: string, phpstan_reference: string, phpstan_config_sha256: string, composer_lock_sha256: string, source_digest: string, semantic_scope?: array{paths: list<string>, excludes: list<string>, scan_directories: list<string>, identity_sha256: string}}|null} */
    public function toArray(): array
    {
        return [
            'map_digest' => $this->mapDigest,
            'backend' => $this->backend,
            'analysis_fingerprint' => $this->analysisFingerprint?->toArray(),
        ];
    }
}
