<?php

declare(strict_types=1);

namespace voku\AgentMap\Build;

final readonly class StructuralOnlySemanticAnalyzer implements SemanticAnalyzer
{
    public function backend(): string
    {
        return 'structural-only';
    }

    public function analyse(
        string $root,
        array $relativeFiles,
        ?string $configurationFile = null,
        ?string $memoryLimit = null,
        array $analyseDirectories = [],
        array $scanDirectories = [],
    ): SemanticAnalysisResult
    {
        return new SemanticAnalysisResult([], [], 'none', 'sha256:none');
    }
}
