<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference;

/** The PHP symbol whose non-PHP references (docs, templates) are collected. */
final readonly class ReferenceTarget
{
    private function __construct(
        public string $classFqn,
        public ?string $member,
    ) {
    }

    public static function classLike(string $fqn): self
    {
        return new self(ltrim($fqn, '\\'), null);
    }

    public static function method(string $ownerFqn, string $name): self
    {
        return new self(ltrim($ownerFqn, '\\'), $name);
    }

    public function shortName(): string
    {
        $parts = explode('\\', $this->classFqn);

        return end($parts);
    }
}
