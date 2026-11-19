<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template\Smarty;

/**
 * A template position that refers to a concrete PHP member, derived from facts rather than from a name match:
 *
 * - `render_context_typed`: `{$user->getName()}` where at least one render site that reaches the template (directly or
 *   through `{include}` / `{extends}`) assigns `user` a statically known type. One reference per known type is reported;
 *   `ambiguous` is true when the variable reaches the template with more than one type, or also untyped.
 * - `registered_plugin`: `{$price|money}` or `{icon}` where PHP registered that modifier / function / block plugin
 *   with a resolvable callback.
 * - `static_reference`: `{Foo::bar()}` written in the template, with `registerClass()` aliases resolved.
 *
 * Experimental: see docs/experiments/template-engine-evidence.md.
 */
final readonly class SmartyTypedReference
{
    public const CONFIDENCE_RENDER_CONTEXT = 'render_context_typed';
    public const CONFIDENCE_REGISTERED_PLUGIN = 'registered_plugin';
    public const CONFIDENCE_STATIC = 'static_reference';

    /** @param list<string> $evidence render sites (`path:line`) or registrations that justify the reference */
    public function __construct(
        public string $confidence,
        public string $path,
        public int $line,
        public int $startFilePos,
        public int $endFilePos,
        public string $text,
        public string $class,
        public ?string $member,
        public bool $ambiguous,
        public array $evidence,
    ) {
    }

    /** `Class::member`, or the function name for a modifier backed by a plain function. */
    public function symbol(): string
    {
        return $this->member === null ? $this->class : $this->class . '::' . $this->member;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'confidence' => $this->confidence,
            'path' => $this->path,
            'line' => $this->line,
            'start_file_pos' => $this->startFilePos,
            'end_file_pos' => $this->endFilePos,
            'text' => $this->text,
            'symbol' => $this->symbol(),
            'ambiguous' => $this->ambiguous,
            'evidence' => $this->evidence,
        ];
    }
}
