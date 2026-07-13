<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

use voku\AgentMap\Index\SymbolEntry;

final readonly class ExtractResult
{
    /**
     * @param list<SymbolEntry> $symbols
     */
    public function __construct(
        public string $file,
        public bool $ok,
        public array $symbols = [],
        public ?string $error = null,
    ) {
    }
}
