<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Extract\SymbolExtractor;
use voku\AgentMap\IO\PhpFileFinder;

final readonly class AgentMapBuilder
{
    private const BACKEND = 'simple';

    public function __construct(
        private PhpFileFinder $finder = new PhpFileFinder(),
        private SymbolExtractor $extractor = new SimplePhpParserSymbolExtractor(),
    ) {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    public function build(string $root, array $paths, array $excludes): AgentMapIndex
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Root directory not found: ' . $root);
        }

        $realRoot = str_replace('\\', '/', $realRoot);
        $relatives = $this->finder->find($realRoot, $paths, $excludes);

        $entries = [];
        foreach ($relatives as $relative) {
            $absolute = $realRoot . '/' . $relative;
            $result = $this->extractor->extract($absolute);
            if (!$result->ok) {
                throw new RuntimeException('Parsing failed for ' . $relative . '.' . ($result->error === null ? '' : ' ' . $result->error));
            }

            $entries[] = new FileEntry(
                $relative,
                (int) filemtime($absolute),
                (string) sha1_file($absolute),
                $this->namespaceFromSymbols($result->symbols),
                $result->symbols,
            );
        }

        return new AgentMapIndex('1.0', date('c'), $realRoot, self::BACKEND, $entries);
    }

    /**
     * @param list<SymbolEntry> $symbols
     */
    private function namespaceFromSymbols(array $symbols): string
    {
        foreach ($symbols as $symbol) {
            if (!str_contains($symbol->fqn, '\\')) {
                continue;
            }

            return substr($symbol->fqn, 0, (int) strrpos($symbol->fqn, '\\'));
        }

        return '';
    }
}
