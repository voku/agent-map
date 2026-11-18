<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template\Smarty;

/**
 * One `registerPlugin()` / `registerClass()` / `addExtension()` call. Since Smarty 5 a template can only reach PHP that
 * was registered, so the set of these calls is the complete set of template-to-PHP entry points that static analysis
 * can see. `callback` is `Class::method` or `function` when statically resolvable, otherwise null.
 *
 * Experimental: see docs/experiments/template-engine-evidence.md.
 */
final readonly class SmartyPluginRegistration
{
    public const KIND_PLUGIN = 'plugin';
    public const KIND_CLASS = 'class';
    public const KIND_EXTENSION = 'extension';

    public function __construct(
        public string $kind,
        public string $path,
        public int $line,
        public ?string $type,
        public ?string $name,
        public ?string $callback,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'path' => $this->path,
            'line' => $this->line,
            'type' => $this->type,
            'name' => $this->name,
            'callback' => $this->callback,
            'resolved' => $this->callback !== null,
        ];
    }
}
