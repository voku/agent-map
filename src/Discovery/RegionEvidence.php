<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class RegionEvidence
{
    /**
     * @param list<string> $interfaceFiles
     * @param list<string> $dominantSignals
     */
    public function __construct(
        public float $internalWeight,
        public float $externalWeight,
        public float $boundaryRatio,
        public float $boundaryStrength,
        public float $internalDensity,
        public float $crosscutScore,
        public float $namespaceAgreement,
        public float $directoryAgreement,
        public array $interfaceFiles,
        public array $dominantSignals,
    ) {
    }
}
