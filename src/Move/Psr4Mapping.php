<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

/** One deterministic PSR-4 prefix/directory pair declared by the analysed project. */
final readonly class Psr4Mapping
{
    public function __construct(
        public string $prefix,
        public string $directory,
        public string $section,
    ) {
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
        return sprintf('%s => %s (%s)', $this->prefix === '' ? '""' : $this->prefix, $this->directory === '' ? '.' : $this->directory, $this->section);
    }
}
