<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class MethodEntry
{
    /**
     * @param list<string> $params
     * @param list<string> $attributes
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public int $lineStart,
        public bool $static = false,
        public array $params = [],
        public ?string $returnType = null,
        public array $attributes = [],
        public int $lineEnd = 0,
    ) {
    }

    /**
     * @return array{name: string, visibility: string, line_start: int, line_end: int, static: bool, params: list<string>, return_type: ?string, attributes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'visibility' => $this->visibility,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'static' => $this->static,
            'params' => $this->params,
            'return_type' => $this->returnType,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @param array{name?: mixed, visibility?: mixed, line_start?: mixed, line_end?: mixed, static?: mixed, params?: mixed, return_type?: mixed, attributes?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        $params = [];
        foreach (is_array($data['params'] ?? null) ? $data['params'] : [] as $param) {
            $params[] = (string) $param;
        }

        $attributes = [];
        foreach (is_array($data['attributes'] ?? null) ? $data['attributes'] : [] as $attribute) {
            $attributes[] = (string) $attribute;
        }

        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['visibility'] ?? 'public'),
            (int) ($data['line_start'] ?? 0),
            (bool) ($data['static'] ?? false),
            $params,
            isset($data['return_type']) ? (string) $data['return_type'] : null,
            $attributes,
            (int) ($data['line_end'] ?? 0),
        );
    }
}
