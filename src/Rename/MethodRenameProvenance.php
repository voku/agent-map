<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use voku\AgentMap\Index\AnalysisFingerprint;

/** Immutable identity of the map and source snapshot used to produce a method rename plan. */
final readonly class MethodRenameProvenance
{
    public function __construct(
        public string $mapDigest,
        public string $backend,
        public ?AnalysisFingerprint $analysisFingerprint,
    ) {
    }

    /** @return array{map_digest: string, backend: string, analysis_fingerprint: array<string, string>|null} */
    public function toArray(): array
    {
        return [
            'map_digest' => $this->mapDigest,
            'backend' => $this->backend,
            'analysis_fingerprint' => $this->analysisFingerprint?->toArray(),
        ];
    }
}
