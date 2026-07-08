<?php

declare(strict_types=1);

namespace voku\AgentMap\Backend;

interface ParallelAstBackend
{
    /**
     * @param list<string> $files absolute paths
     *
     * @return array<string, AstResult> keyed by absolute path
     */
    public function parseMany(array $files, int $workers): array;
}
