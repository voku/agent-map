<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template;

/**
 * One parser-level fact about a template, engine-neutral: an include/extends edge, a variable member access, a
 * filter/modifier use, a tag (function/block call), or a static PHP reference. Positions are exact byte ranges in the template source.
 *
 * Experimental: not part of the Map schema or any plan contract.
 */
final readonly class TemplateFact
{
    public const KIND_INCLUDE = 'include';
    public const KIND_EXTENDS = 'extends';
    public const KIND_VARIABLE_MEMBER = 'variable_member';
    public const KIND_MODIFIER = 'modifier';
    public const KIND_STATIC_REFERENCE = 'static_reference';
    public const KIND_TAG = 'tag';

    /**
     * @param string|null $target  include/extends: template name (null when dynamic); variable_member: variable name;
     *                             modifier: modifier name; static_reference: class name as written; tag: tag name
     * @param string|null $member  variable_member / static_reference: the member name
     * @param bool        $call    the member is called (`->name(`), not read
     * @param bool        $dynamic the target is computed at runtime and cannot be resolved statically
     * @param array<string, string> $arguments include: named arguments whose value is a plain variable (`u=$user` => `user`)
     */
    public function __construct(
        public string $kind,
        public string $path,
        public int $line,
        public int $startFilePos,
        public int $endFilePos,
        public string $text,
        public ?string $target,
        public ?string $member = null,
        public bool $call = false,
        public bool $dynamic = false,
        public array $arguments = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'path' => $this->path,
            'line' => $this->line,
            'start_file_pos' => $this->startFilePos,
            'end_file_pos' => $this->endFilePos,
            'text' => $this->text,
            'target' => $this->target,
            'member' => $this->member,
            'call' => $this->call ?: null,
            'dynamic' => $this->dynamic ?: null,
            'arguments' => $this->arguments ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
