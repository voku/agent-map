<?php

declare(strict_types=1);

namespace voku\AgentMap\Prepare;

use voku\AgentMap\Index\AgentMapIndex;

final readonly class MapPreparationResult
{
    public function __construct(
        public AgentMapIndex $index,
        public bool $mutated,
        public int $changedFiles,
        public int $removedFiles,
        public string $message,
    ) {
    }
}
