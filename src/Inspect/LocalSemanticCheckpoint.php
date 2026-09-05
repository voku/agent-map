<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

interface LocalSemanticCheckpoint
{
    public function kind(): string;

    public function line(): int;

    public function startFilePos(): ?int;

    public function toText(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
