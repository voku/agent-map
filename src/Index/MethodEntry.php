<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class MethodEntry
{
    /**
     * @param list<ParameterEntry> $parameters
     * @param list<string> $attributes
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public int $lineStart,
        public int $lineEnd = 0,
        public bool $static = false,
        public bool $abstract = false,
        public bool $final = false,
        public array $parameters = [],
        public ?string $nativeReturnType = null,
        public ?string $phpDocReturnType = null,
        public ?string $resolvedReturnType = null,
        public array $attributes = [],
        public string $reconciliationStatus = 'structural_only',
    ) {
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
     * @return array{name: string, visibility: string, line_start: int, line_end: int, static: bool, abstract: bool, final: bool, parameters: list<array{name: string, native_type: ?string, phpdoc_type: ?string, resolved_type: ?string, by_reference: bool, variadic: bool}>, native_return_type: ?string, phpdoc_return_type: ?string, resolved_return_type: ?string, attributes: list<string>, reconciliation_status: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'visibility' => $this->visibility,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'static' => $this->static,
            'abstract' => $this->abstract,
            'final' => $this->final,
            'parameters' => array_map(static fn (ParameterEntry $parameter): array => $parameter->toArray(), $this->parameters),
            'native_return_type' => $this->nativeReturnType,
            'phpdoc_return_type' => $this->phpDocReturnType,
            'resolved_return_type' => $this->resolvedReturnType,
            'attributes' => $this->attributes,
            'reconciliation_status' => $this->reconciliationStatus,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $parameters = [];
        $rawParameters = $data['parameters'] ?? $data['params'] ?? [];
        foreach (is_array($rawParameters) ? $rawParameters : [] as $key => $parameter) {
            if (is_array($parameter)) {
                $parameters[] = ParameterEntry::fromArray($parameter);
                continue;
            }
            if (is_string($parameter)) {
                $parameters[] = ParameterEntry::fromLegacyString($parameter, is_string($key) ? $key : null);
            }
        }

        $attributes = [];
        foreach (is_array($data['attributes'] ?? null) ? $data['attributes'] : [] as $attribute) {
            $attributes[] = (string) $attribute;
        }

        $legacyReturnType = self::nullableString($data['return_type'] ?? null);

        return new self(
            name: (string) ($data['name'] ?? ''),
            visibility: (string) ($data['visibility'] ?? 'public'),
            lineStart: (int) ($data['line_start'] ?? 0),
            lineEnd: (int) ($data['line_end'] ?? 0),
            static: (bool) ($data['static'] ?? false),
            abstract: (bool) ($data['abstract'] ?? false),
            final: (bool) ($data['final'] ?? false),
            parameters: $parameters,
            nativeReturnType: self::nullableString($data['native_return_type'] ?? null) ?? $legacyReturnType,
            phpDocReturnType: self::nullableString($data['phpdoc_return_type'] ?? null),
            resolvedReturnType: self::nullableString($data['resolved_return_type'] ?? null),
            attributes: $attributes,
            reconciliationStatus: (string) ($data['reconciliation_status'] ?? 'structural_only'),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
