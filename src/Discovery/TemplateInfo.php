<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class TemplateInfo
{
    /**
     * @param list<string> $includes
     * @param list<array{action: ?string, method: ?string, params: array<string, string>}> $forms
     * @param list<string> $inputs
     * @param list<string> $xajax
     * @param list<string> $renderedBySymbols
     */
    public function __construct(
        public string $path,
        public string $name,
        public string $engine,
        public array $includes = [],
        public array $forms = [],
        public array $inputs = [],
        public array $xajax = [],
        public array $renderedBySymbols = [],
    ) {
    }

    /**
     * @return array{path: string, name: string, engine: string, includes: list<string>, forms: list<array{action: ?string, method: ?string, params: array<string, string>}>, inputs: list<string>, xajax: list<string>, rendered_by: list<string>}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'engine' => $this->engine,
            'includes' => $this->includes,
            'forms' => $this->forms,
            'inputs' => $this->inputs,
            'xajax' => $this->xajax,
            'rendered_by' => $this->renderedBySymbols,
        ];
    }
}
