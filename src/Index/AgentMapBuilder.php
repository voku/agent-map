<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\SemanticAnalyzer;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Extract\SymbolExtractor;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\Reconcile\MapReconciler;

final readonly class AgentMapBuilder
{
    private const BACKEND = 'simple-php-code-parser+phpstan';

    public function __construct(
        private PhpFileFinder $finder = new PhpFileFinder(),
        private SymbolExtractor $extractor = new SimplePhpParserSymbolExtractor(),
        private SemanticAnalyzer $semanticAnalyzer = new PhpStanSemanticAnalyzer(),
        private MapReconciler $reconciler = new MapReconciler(),
    ) {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    public function build(string $root, array $paths, array $excludes, ?string $phpStanConfiguration = null): AgentMapIndex
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Root directory not found: ' . $root);
        }

        $realRoot = str_replace('\\', '/', $realRoot);
        $relatives = $this->finder->find($realRoot, $paths, $excludes);

        $structuralFiles = [];
        $sourceHashes = [];
        foreach ($relatives as $relative) {
            $absolute = $realRoot . '/' . $relative;
            $result = $this->extractor->extract($absolute);
            if (!$result->ok) {
                throw new RuntimeException('Parsing failed for ' . $relative . '.' . ($result->error === null ? '' : ' ' . $result->error));
            }

            $sha256 = hash_file('sha256', $absolute);
            if (!is_string($sha256)) {
                throw new RuntimeException('Unable to hash PHP file: ' . $relative);
            }
            $sourceHashes[$relative] = $sha256;
            $structuralFiles[] = new FileEntry(
                path: $relative,
                sha256: 'sha256:' . $sha256,
                namespace: $this->namespaceFromSymbols($result->symbols),
                symbols: $result->symbols,
                semanticStatus: 'pending',
            );
        }

        $configuration = $this->resolvePhpStanConfiguration($realRoot, $phpStanConfiguration);
        $semantic = $this->semanticAnalyzer->analyse($realRoot, $relatives, $configuration);
        $reconciled = $this->reconciler->reconcile($realRoot, $structuralFiles, $semantic);

        ksort($sourceHashes, SORT_STRING);
        $sourceDigestParts = [];
        foreach ($sourceHashes as $relative => $hash) {
            $sourceDigestParts[] = $relative . "\0" . $hash;
        }
        $composerLockHash = is_file($realRoot . '/composer.lock') ? hash_file('sha256', $realRoot . '/composer.lock') : false;

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: $realRoot,
            backend: self::BACKEND,
            files: $reconciled['files'],
            relations: $reconciled['relations'],
            diagnostics: $reconciled['diagnostics'],
            fingerprint: new AnalysisFingerprint(
                phpStanVersion: $semantic->phpStanVersion,
                phpStanConfigSha256: $semantic->configurationSha256,
                composerLockSha256: is_string($composerLockHash) ? 'sha256:' . $composerLockHash : 'sha256:none',
                sourceDigest: 'sha256:' . hash('sha256', implode("\n", $sourceDigestParts)),
            ),
        );
    }

    private function resolvePhpStanConfiguration(string $root, ?string $configuration): ?string
    {
        if ($configuration !== null) {
            $candidate = str_starts_with($configuration, '/') ? $configuration : $root . '/' . $configuration;
            if (!is_file($candidate)) {
                throw new RuntimeException('PHPStan configuration not found: ' . $configuration);
            }

            return $candidate;
        }

        foreach (['phpstan.neon', 'phpstan.neon.dist'] as $name) {
            if (is_file($root . '/' . $name)) {
                return $root . '/' . $name;
            }
        }

        return null;
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
