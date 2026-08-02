<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

final readonly class MapSnapshot
{
    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $files
     * @param array<string, mixed> $symbols
     * @param array<string, mixed> $relations
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public array $manifest,
        public array $files,
        public array $symbols,
        public array $relations,
        public array $diagnostics,
    ) {
    }
}
