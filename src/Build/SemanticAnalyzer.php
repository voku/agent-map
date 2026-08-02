<?php

declare(strict_types=1);

namespace voku\AgentMap\Build;

interface SemanticAnalyzer
{
    /**
     * @param list<string> $relativeFiles
     */
    public function analyse(string $root, array $relativeFiles, ?string $configurationFile = null): SemanticAnalysisResult;
}
