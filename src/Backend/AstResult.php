<?php

declare(strict_types=1);

namespace voku\AgentMap\Backend;

final readonly class AstResult
{
    public function __construct(
        public string $file,
        public bool $ok,
        public ?string $error = null,
    ) {
    }
}
