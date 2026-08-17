<?php

declare(strict_types=1);

namespace voku\AgentMap\Build;

interface SemanticAnalyzer
{
    public function backend(): string;

    /**
     * @param list<string> $relativeFiles every file the map covers, relative to `$root`
     * @param list<string> $analyseDirectories directories the scope came from, relative to `$root`;
     *                                         when given, the analyser may hand these to the backend
     *                                         instead of the expanded file list
     * @param list<string> $scanDirectories directories that only need to be known for symbol
     *                                      resolution, not analysed
     */
    public function analyse(
        string $root,
        array $relativeFiles,
        ?string $configurationFile = null,
        ?string $memoryLimit = null,
        array $analyseDirectories = [],
        array $scanDirectories = [],
    ): SemanticAnalysisResult;
}
