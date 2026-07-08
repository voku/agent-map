<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class SymbolEntry
{
    /**
     * @param list<MethodEntry> $methods
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $fqn,
        public int $lineStart,
        public int $lineEnd,
        public array $methods = [],
    ) {
    }

    /**
     * @return array{kind: string, name: string, fqn: string, line_start: int, line_end: int, methods: list<array{name: string, visibility: string, line_start: int}>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'name' => $this->name,
            'fqn' => $this->fqn,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'methods' => array_map(static fn (MethodEntry $method): array => $method->toArray(), $this->methods),
        ];
    }

    /**
     * @param array{kind?: mixed, name?: mixed, fqn?: mixed, line_start?: mixed, line_end?: mixed, methods?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        $methods = [];
        foreach (is_array($data['methods'] ?? null) ? $data['methods'] : [] as $method) {
            if (is_array($method)) {
                $methods[] = MethodEntry::fromArray($method);
            }
        }

        return new self(
            (string) ($data['kind'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['fqn'] ?? ''),
            (int) ($data['line_start'] ?? 0),
            (int) ($data['line_end'] ?? 0),
            $methods,
        );
    }
}
