<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template;

/**
 * How one template engine marks the parts of a file that can mention PHP symbols.
 *
 * A dialect only answers "where is template code, and how does attribute access behave?". It does not resolve
 * types or prove a reference: every hit found through a dialect stays residue evidence. Projects with another engine,
 * or with non-default settings (Smarty delimiters, extra file extensions), pass their own dialects to
 * {@see \voku\AgentMap\Reference\NonPhpReferenceScanner}.
 */
interface TemplateDialect
{
    /** Stable engine name, e.g. `twig`, `blade`, `smarty`. */
    public function engine(): string;

    /** Whether this dialect owns the file; `$relativePath` is repository-relative with `/` separators. */
    public function handles(string $relativePath): bool;

    /**
     * Byte ranges of template code (tags, expressions, and comments that carry type hints) in `$source`, as
     * half-open `[start, end)` pairs. Literal text, markup and ignored comments are outside every range.
     *
     * @return list<array{int, int}>
     */
    public function codeRegions(string $source): array;

    /**
     * Whether `x.name` / `x->name` in this engine may call the PHP getter `getName()` / `isName()` / `hasName()`
     * (true for Twig's attribute lookup; false where `.name` is an array key or `->name` a plain property).
     */
    public function attributeReachesGetter(): bool;
}
