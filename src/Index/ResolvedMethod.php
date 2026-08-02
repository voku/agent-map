<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class ResolvedMethod
{
    public function __construct(
        public string $id,
        public FileEntry $file,
        public SymbolEntry $owner,
        public MethodEntry $method,
    ) {
    }
}
