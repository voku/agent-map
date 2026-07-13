<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class SymbolEntry
{
    /**
     * @param list<string> $extends
     * @param list<string> $implements
     * @param list<string> $uses
     * @param list<MethodEntry> $methods
     * @param list<string> $params
     * @param list<string> $attributes
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $fqn,
        public int $lineStart,
        public int $lineEnd,
        public array $methods = [],
        public array $extends = [],
        public array $implements = [],
        public array $params = [],
        public ?string $returnType = null,
        public array $attributes = [],
        public array $uses = [],
    ) {
    }

    /**
     * @return array{kind: string, name: string, fqn: string, line_start: int, line_end: int, extends: list<string>, implements: list<string>, uses: list<string>, params: list<string>, return_type: ?string, attributes: list<string>, methods: list<array{name: string, visibility: string, line_start: int, line_end: int, static: bool, params: list<string>, return_type: ?string, attributes: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'name' => $this->name,
            'fqn' => $this->fqn,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'extends' => $this->extends,
            'implements' => $this->implements,
            'uses' => $this->uses,
            'params' => $this->params,
            'return_type' => $this->returnType,
            'attributes' => $this->attributes,
            'methods' => array_map(static fn (MethodEntry $method): array => $method->toArray(), $this->methods),
        ];
    }

    /**
     * @param array{kind?: mixed, name?: mixed, fqn?: mixed, line_start?: mixed, line_end?: mixed, extends?: mixed, implements?: mixed, uses?: mixed, params?: mixed, return_type?: mixed, attributes?: mixed, methods?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        $methods = [];
        foreach (is_array($data['methods'] ?? null) ? $data['methods'] : [] as $method) {
            if (is_array($method)) {
                $methods[] = MethodEntry::fromArray($method);
            }
        }

        $extends = [];
        foreach (is_array($data['extends'] ?? null) ? $data['extends'] : [] as $type) {
            $extends[] = (string) $type;
        }

        $implements = [];
        foreach (is_array($data['implements'] ?? null) ? $data['implements'] : [] as $type) {
            $implements[] = (string) $type;
        }

        $uses = [];
        foreach (is_array($data['uses'] ?? null) ? $data['uses'] : [] as $type) {
            $uses[] = (string) $type;
        }

        $params = [];
        foreach (is_array($data['params'] ?? null) ? $data['params'] : [] as $param) {
            $params[] = (string) $param;
        }

        $attributes = [];
        foreach (is_array($data['attributes'] ?? null) ? $data['attributes'] : [] as $attribute) {
            $attributes[] = (string) $attribute;
        }

        return new self(
            (string) ($data['kind'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['fqn'] ?? ''),
            (int) ($data['line_start'] ?? 0),
            (int) ($data['line_end'] ?? 0),
            $methods,
            $extends,
            $implements,
            $params,
            isset($data['return_type']) ? (string) $data['return_type'] : null,
            $attributes,
            $uses,
        );
    }
}
