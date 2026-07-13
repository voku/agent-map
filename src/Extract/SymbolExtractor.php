<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

interface SymbolExtractor
{
    public function extract(string $file): ExtractResult;
}
