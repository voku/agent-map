<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentMap\Backend\AstBackend;
use voku\AgentMap\Backend\AstResult;
use voku\AgentMap\Backend\ParallelAstBackend;
use voku\AgentMap\Extract\PhpTokenSymbolExtractor;
use voku\AgentMap\IO\PhpFileFinder;

final readonly class AgentMapBuilder
{
    public function __construct(
        private PhpFileFinder $finder = new PhpFileFinder(),
        private PhpTokenSymbolExtractor $extractor = new PhpTokenSymbolExtractor(),
    ) {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    public function build(string $root, array $paths, array $excludes, string $backendName, AstBackend $backend, int $workers = 1): AgentMapIndex
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Root directory not found: ' . $root);
        }

        $realRoot = str_replace('\\', '/', $realRoot);
        $relatives = $this->finder->find($realRoot, $paths, $excludes);
        $results = $this->parse($backend, $realRoot, $relatives, $workers);

        $entries = [];
        foreach ($relatives as $relative) {
            $absolute = $realRoot . '/' . $relative;
            $result = $results[$absolute];
            if (!$result->ok) {
                throw new RuntimeException('Mago backend failed for ' . $relative . '. Either install mago or use --backend=token.' . ($result->error === null ? '' : ' ' . $result->error));
            }

            $code = file_get_contents($absolute);
            if (!is_string($code)) {
                throw new RuntimeException('Unable to read PHP file: ' . $relative);
            }

            $symbols = $this->extractor->extract($absolute, $code);
            $entries[] = new FileEntry(
                $relative,
                (int) filemtime($absolute),
                (string) sha1_file($absolute),
                $this->namespaceFromSymbols($symbols),
                $symbols,
            );
        }

        return new AgentMapIndex('1.0', date('c'), $realRoot, $backendName, $entries);
    }

    /**
     * @param list<string> $relatives
     *
     * @return array<string, AstResult> keyed by absolute path
     */
    private function parse(AstBackend $backend, string $realRoot, array $relatives, int $workers): array
    {
        $absolutes = array_map(static fn (string $relative): string => $realRoot . '/' . $relative, $relatives);

        if ($backend instanceof ParallelAstBackend && $workers > 1) {
            return $backend->parseMany($absolutes, $workers);
        }

        $results = [];
        foreach ($absolutes as $absolute) {
            $results[$absolute] = $backend->parse($absolute);
        }

        return $results;
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
