<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

interface SymbolExtractor
{
    public function extract(string $file): ExtractResult;

    /**
     * @param list<string> $files
     *
     * @return array<string, ExtractResult> map of file path => ExtractResult
     */
    public function extractMany(array $files): array;
}
