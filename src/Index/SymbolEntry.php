<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class SymbolEntry
{
    /**
     * @param list<MethodEntry> $methods
     * @param list<string> $extends
     * @param list<string> $implements
     * @param list<ParameterEntry> $parameters
     * @param list<string> $attributes
     * @param list<string> $uses
     * @param array<string, string> $templates
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $fqn,
        public int $lineStart,
        public int $lineEnd = 0,
        public array $methods = [],
        public array $extends = [],
        public array $implements = [],
        public array $parameters = [],
        public ?string $nativeReturnType = null,
        public ?string $phpDocReturnType = null,
        public ?string $resolvedReturnType = null,
        public array $attributes = [],
        public array $uses = [],
        public array $templates = [],
        public string $reconciliationStatus = 'structural_only',
    ) {
    }

    public function id(): string
    {
        return $this->kind . ':' . ltrim($this->fqn, '\\');
    }

    public function methodId(MethodEntry $method): string
    {
        return 'method:' . ltrim($this->fqn, '\\') . '::' . $method->name;
    }

    /**
     * @return list<string>
     */
    public function displayParameters(): array
    {
        return array_map(static fn (ParameterEntry $parameter): string => $parameter->display(), $this->parameters);
    }

    public function displayReturnType(): ?string
    {
        return $this->resolvedReturnType ?? $this->phpDocReturnType ?? $this->nativeReturnType;
    }

    /**
     * @return array<string, mixed>
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
            'parameters' => array_map(static fn (ParameterEntry $parameter): array => $parameter->toArray(), $this->parameters),
            'native_return_type' => $this->nativeReturnType,
            'phpdoc_return_type' => $this->phpDocReturnType,
            'resolved_return_type' => $this->resolvedReturnType,
            'attributes' => $this->attributes,
            'templates' => $this->templates,
            'reconciliation_status' => $this->reconciliationStatus,
            'methods' => array_map(static fn (MethodEntry $method): array => $method->toArray(), $this->methods),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $methods = [];
        foreach (is_array($data['methods'] ?? null) ? $data['methods'] : [] as $method) {
            if (is_array($method)) {
                $methods[] = MethodEntry::fromArray($method);
            }
        }

        $parameters = [];
        foreach (is_array($data['parameters'] ?? null) ? $data['parameters'] : [] as $parameter) {
            if (is_array($parameter)) {
                $parameters[] = ParameterEntry::fromArray($parameter);
            }
        }

        return new self(
            kind: (string) ($data['kind'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            fqn: (string) ($data['fqn'] ?? ''),
            lineStart: (int) ($data['line_start'] ?? 0),
            lineEnd: (int) ($data['line_end'] ?? 0),
            methods: $methods,
            extends: self::stringList($data['extends'] ?? []),
            implements: self::stringList($data['implements'] ?? []),
            parameters: $parameters,
            nativeReturnType: self::nullableString($data['native_return_type'] ?? null),
            phpDocReturnType: self::nullableString($data['phpdoc_return_type'] ?? null),
            resolvedReturnType: self::nullableString($data['resolved_return_type'] ?? null),
            attributes: self::stringList($data['attributes'] ?? []),
            uses: self::stringList($data['uses'] ?? []),
            templates: self::stringMap($data['templates'] ?? []),
            reconciliationStatus: (string) ($data['reconciliation_status'] ?? 'structural_only'),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $result[] = (string) $item;
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key)) {
                $result[$key] = (string) $item;
            }
        }

        return $result;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
