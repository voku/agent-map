<?php

declare(strict_types=1);

namespace voku\AgentMap\Build;

final readonly class SemanticAnalysisResult
{
    /**
     * @param list<array<string, mixed>> $records
     * @param list<string> $findings
     */
    public function __construct(
        public array $records,
        public array $findings,
        public string $phpStanVersion,
        public string $configurationSha256,
    ) {
    }
}
