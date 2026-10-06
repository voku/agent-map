<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference;

/**
 * One place outside PHP source that appears to mention a target symbol.
 *
 * This is residue evidence, never edit authority: a mention is not a proof that the text means the
 * symbol, so it can never make a plan safer or less safe.
 */
final readonly class NonPhpReference
{
    public const KIND_MARKDOWN = 'markdown_reference';
    public const KIND_TEMPLATE = 'template_reference_candidate';

    /** The text spells the fully qualified class name (or Class::member with it). */
    public const CONFIDENCE_EXACT_FQCN = 'exact_fqcn';
    /** The text spells `Short::member` or `Short->member`. */
    public const CONFIDENCE_CLASS_MEMBER = 'class_member_qualified';
    /** The short class name inside a code span or fenced block. */
    public const CONFIDENCE_CODE_NAME = 'code_name';
    /** Only the member name appears; the receiver type is unknown. */
    public const CONFIDENCE_MEMBER_NAME_ONLY = 'member_name_only';

    public function __construct(
        public string $kind,
        public string $confidence,
        public string $path,
        public int $line,
        public int $startFilePos,
        public int $endFilePos,
        public string $matched,
        public bool $historical,
    ) {
    }

    /** @return array{kind: string, confidence: string, path: string, line: int, start_file_pos: int, end_file_pos: int, matched: string, historical: bool} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'confidence' => $this->confidence,
            'path' => $this->path,
            'line' => $this->line,
            'start_file_pos' => $this->startFilePos,
            'end_file_pos' => $this->endFilePos,
            'matched' => $this->matched,
            'historical' => $this->historical,
        ];
    }
}
