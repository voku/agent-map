<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

use voku\AgentMap\Index\SymbolEntry;

final readonly class ExtractResult
{
    /**
     * @param list<SymbolEntry> $symbols
     * @param string|null $toolVersion version of the external tool that parsed the file, when its
     *                                 identity is not already fixed by composer.lock
     */
    public function __construct(
        public string $file,
        public bool $ok,
        public array $symbols = [],
        public ?string $error = null,
        public ?string $toolVersion = null,
    ) {
    }
}
