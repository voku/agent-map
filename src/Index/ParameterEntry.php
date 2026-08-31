<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class ParameterEntry
{
    public function __construct(
        public string $name,
        public ?string $nativeType = null,
        public ?string $phpDocType = null,
        public ?string $resolvedType = null,
        public bool $byReference = false,
        public bool $variadic = false,
    ) {
    }

    public function displayType(): ?string
    {
        return $this->resolvedType ?? $this->phpDocType ?? $this->nativeType;
    }

    public function display(): string
    {
        $type = $this->displayType();
        $marker = ($this->byReference ? '&' : '') . ($this->variadic ? '...' : '');

        return trim(($type !== null && $type !== '' ? $type . ' ' : '') . $marker . '$' . $this->name);
    }

    /**
     * @return array{name: string, native_type: ?string, phpdoc_type: ?string, resolved_type: ?string, by_reference: bool, variadic: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'native_type' => $this->nativeType,
            'phpdoc_type' => $this->phpDocType,
            'resolved_type' => $this->resolvedType,
            'by_reference' => $this->byReference,
            'variadic' => $this->variadic,
        ];
    }

    /**
     * @param array{name?: mixed, native_type?: mixed, phpdoc_type?: mixed, resolved_type?: mixed, by_reference?: mixed, variadic?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            nativeType: self::nullableString($data['native_type'] ?? null),
            phpDocType: self::nullableString($data['phpdoc_type'] ?? null),
            resolvedType: self::nullableString($data['resolved_type'] ?? null),
            byReference: (bool) ($data['by_reference'] ?? false),
            variadic: (bool) ($data['variadic'] ?? false),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
