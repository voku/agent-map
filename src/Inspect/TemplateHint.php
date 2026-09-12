<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class TemplateHint
{
    public function __construct(
        public string $path,
        public int $line,
        public string $kind = 'file',
        public ?string $engine = null,
        public ?string $caller = null,
    ) {
    }

    /**
     * @return array{path: string, line: int, kind?: string, engine?: ?string, caller?: ?string}
     */
    public function toArray(): array
    {
        $arr = [
            'path' => $this->path,
            'line' => $this->line,
        ];
        if ($this->kind !== 'file') {
            $arr['kind'] = $this->kind;
        }
        if ($this->engine !== null) {
            $arr['engine'] = $this->engine;
        }
        if ($this->caller !== null) {
            $arr['caller'] = $this->caller;
        }

        return $arr;
    }
}
