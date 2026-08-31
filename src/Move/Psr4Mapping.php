<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

use voku\AgentMap\Plan\ProjectRelativePath;

/** One deterministic PSR-4 prefix/directory pair declared by the analysed project. */
final readonly class Psr4Mapping
{
    private function __construct(
        public string $prefix,
        public string $declaredDirectory,
        public string $directory,
        public string $section,
        public bool $insideProject,
    ) {
    }

    /**
     * Reads one declared mapping without tidying it up.
     *
     * A declared directory of `/opt/lib` or `../sibling/src` is a real answer about where the
     * autoloader looks, and it is outside this project. Trimming the leading slash away would turn
     * it into a plausible in-project path, which is how a move plan ends up naming a destination
     * nobody declared - so the declaration is judged as written, before any normalization.
     */
    public static function fromDeclaration(string $prefix, string $directory, string $section): self
    {
        $declared = str_replace('\\', '/', $directory);
        $relative = str_starts_with($declared, './') ? substr($declared, 2) : $declared;

        return new self(
            prefix: self::normalizePrefix($prefix),
            declaredDirectory: $directory,
            directory: trim($relative, '/'),
            section: $section,
            insideProject: $relative === '' || ProjectRelativePath::isSafe($relative),
        );
    }

    /** Derives the single PSR-4 path this mapping prescribes for a class identity it covers. */
    public function pathFor(string $fqn): string
    {
        $relative = str_replace('\\', '/', substr($fqn, strlen($this->prefix)));

        return $this->directory === '' ? $relative . '.php' : $this->directory . '/' . $relative . '.php';
    }

    public function covers(string $fqn): bool
    {
        return $this->prefix === '' || str_starts_with($fqn, $this->prefix);
    }

    public function label(): string
    {
        return sprintf(
            '%s => %s (%s)',
            $this->prefix === '' ? '""' : $this->prefix,
            $this->declaredDirectory === '' ? '.' : $this->declaredDirectory,
            $this->section,
        );
    }

    private static function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '\\');

        return $prefix === '' ? '' : $prefix . '\\';
    }
}
