<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

/**
 * An extractor whose symbol identity depends on the analysed project root.
 *
 * `SymbolExtractor` only receives absolute file paths. An extractor that names symbols by their
 * project-relative path is told the root by the builder, once per build.
 */
interface ProjectRootAware
{
    public function withProjectRoot(string $root): SymbolExtractor;
}
