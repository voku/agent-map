<?php

declare(strict_types=1);

namespace voku\AgentMap\Build;

/** Intended for focused unit tests; production builds use PHPStanSemanticAnalyzer. */
final readonly class StructuralOnlySemanticAnalyzer implements SemanticAnalyzer
{
    public function analyse(
        string $root,
        array $relativeFiles,
        ?string $configurationFile = null,
        ?string $memoryLimit = null,
        array $analyseDirectories = [],
        array $scanDirectories = [],
    ): SemanticAnalysisResult
    {
        return new SemanticAnalysisResult([], [], 'test-none', 'sha256:none');
    }
}
