<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template\Smarty;

/**
 * One PHP call that renders a Smarty template (`display()` / `fetch()`), with the variables assigned to the same
 * receiver earlier in the same function. A variable's type is set only when it is statically clear (a `new`
 * expression, a declared parameter or property type, or a local assigned from `new`); otherwise it is null.
 *
 * Experimental: see docs/experiments/template-engine-evidence.md.
 */
final readonly class SmartyRenderSite
{
    /**
     * @param string|null $template the template name as written (null when computed at runtime)
     * @param array<string, array{expression: string, type: string|null}> $variables
     * @param bool $receiverProven the receiver is statically known to be a Smarty object (not just something with `display()`)
     */
    public function __construct(
        public string $path,
        public int $line,
        public string $method,
        public ?string $template,
        public array $variables,
        public bool $receiverProven,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'line' => $this->line,
            'method' => $this->method,
            'template' => $this->template,
            'dynamic' => $this->template === null,
            'receiver_proven' => $this->receiverProven,
            'variables' => $this->variables,
        ];
    }
}
