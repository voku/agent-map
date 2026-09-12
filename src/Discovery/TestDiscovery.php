<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Inspect\ScopeSelector;

final class TestDiscovery
{
    public function __construct(
        private readonly ScopeSelector $scopeSelector = new ScopeSelector(),
    ) {
    }

    public function discover(AgentMapIndex $map, string $query): TestReport
    {
        $targetKind = 'symbol';
        /** @var list<array{symbol: string, file: string, line: int, kind: string}> $targets */
        $targets = [];
        /** @var list<FileEntry> $targetFiles */
        $targetFiles = [];
        /** @var list<string> $targetSymbolIds */
        $targetSymbolIds = [];

        // 1. Check if query is a file path
        $matchedFile = null;
        $cleanQuery = str_replace('\\', '/', $query);
        foreach ($map->files as $file) {
            $normPath = str_replace('\\', '/', $file->path);
            if ($normPath === $cleanQuery || str_ends_with($normPath, '/' . ltrim($cleanQuery, '/'))) {
                $matchedFile = $file;
                break;
            }
        }

        if ($matchedFile !== null) {
            $targetKind = 'file';
            $targetFiles[] = $matchedFile;
            foreach ($matchedFile->symbols as $sym) {
                $targets[] = [
                    'symbol' => $sym->fqn,
                    'file' => $matchedFile->path,
                    'line' => $sym->lineStart,
                    'kind' => $sym->kind,
                ];
                $targetSymbolIds[] = $sym->id();
                foreach ($sym->methods as $m) {
                    $targetSymbolIds[] = $sym->methodId($m);
                }
            }
        } else {
            // 2. Try ScopeSelector for exact method, class, or function
            $selection = $this->scopeSelector->select($map, $query);
            if ($selection->status === 'found' && $selection->target !== null) {
                $targetKind = 'symbol';
                $t = $selection->target;
                $targets[] = [
                    'symbol' => $t->label,
                    'file' => $t->file,
                    'line' => $t->lineStart,
                    'kind' => $t->kind,
                ];

                foreach ($map->files as $file) {
                    if ($file->path === $t->file) {
                        $targetFiles[] = $file;
                        break;
                    }
                }

                if ($t->kind === 'method') {
                    $targetSymbolIds[] = $t->sourceId ?? ('method:' . ltrim($t->label, '\\'));
                } elseif ($t->kind === 'function') {
                    $targetSymbolIds[] = 'func:' . ltrim($t->label, '\\');
                } else {
                    $targetSymbolIds[] = $t->sourceId ?? ($t->kind . ':' . ltrim($t->label, '\\'));
                    // Also include all methods of the target symbol
                    if ($targetFiles !== []) {
                        foreach ($targetFiles[0]->symbols as $sym) {
                            if ($sym->fqn === $t->label) {
                                foreach ($sym->methods as $m) {
                                    $targetSymbolIds[] = $sym->methodId($m);
                                }
                            }
                        }
                    }
                }
            } else {
                // 3. Fallback: query search
                $targetKind = 'keyword';
                $queryResult = $map->query($query);
                foreach ($queryResult->files as $file) {
                    $targetFiles[] = $file;
                    foreach ($file->symbols as $sym) {
                        if (stripos($sym->fqn, $query) !== false) {
                            $targets[] = [
                                'symbol' => $sym->fqn,
                                'file' => $file->path,
                                'line' => $sym->lineStart,
                                'kind' => $sym->kind,
                            ];
                            $targetSymbolIds[] = $sym->id();
                            foreach ($sym->methods as $m) {
                                $targetSymbolIds[] = $sym->methodId($m);
                            }
                        }
                    }
                }
            }
        }

        // Deduplicate target files
        $uniqueTargetFiles = [];
        foreach ($targetFiles as $tf) {
            $uniqueTargetFiles[$tf->path] = $tf;
        }
        $targetFiles = array_values($uniqueTargetFiles);

        // Deduplicate targets
        $uniqueTargets = [];
        foreach ($targets as $t) {
            $key = $t['symbol'] . '@' . $t['file'] . ':' . $t['line'];
            $uniqueTargets[$key] = $t;
        }
        $targets = array_values($uniqueTargets);

        // 4. Find direct test calls
        /** @var array<string, array{test_symbol: string, test_file: string, line: int, target_symbol: string, kind: string}> $testCalls */
        $testCalls = [];

        // Build target ID lookup map including contract overrides
        $allTargetIds = [];
        foreach ($targetSymbolIds as $tid) {
            $allTargetIds[$tid] = true;
            foreach ($map->outgoing($tid, 'overrides') as $contractRel) {
                foreach ($contractRel->targetIds as $cid) {
                    $allTargetIds[$cid] = true;
                }
            }
            foreach ($map->incoming($tid, 'overrides') as $overrideRel) {
                $allTargetIds[$overrideRel->sourceId] = true;
            }
        }

        // Lookup files by path for fast test method resolution
        $filesByPath = [];
        foreach ($map->files as $f) {
            $filesByPath[$f->path] = $f;
        }

        foreach (array_keys($allTargetIds) as $tid) {
            foreach ($map->incoming($tid, 'calls') as $rel) {
                if (!$map->looksLikeTestPath($rel->file)) {
                    continue;
                }

                $testSymbol = $this->resolveEnclosingTestSymbol($rel, $filesByPath[$rel->file] ?? null);
                $key = $testSymbol . '@' . $rel->file . ':' . $rel->lineStart . '->' . $tid;

                $targetDisplay = $this->cleanTargetSymbolId($tid);

                $testCalls[$key] = [
                    'test_symbol' => $testSymbol,
                    'test_file' => $rel->file,
                    'line' => $rel->lineStart,
                    'target_symbol' => $targetDisplay,
                    'kind' => 'call',
                ];
            }

            foreach ($map->incoming($tid, 'instantiates') as $rel) {
                if (!$map->looksLikeTestPath($rel->file)) {
                    continue;
                }

                $testSymbol = $this->resolveEnclosingTestSymbol($rel, $filesByPath[$rel->file] ?? null);
                $key = $testSymbol . '@' . $rel->file . ':' . $rel->lineStart . '->' . $tid;

                $targetDisplay = $this->cleanTargetSymbolId($tid);

                $testCalls[$key] = [
                    'test_symbol' => $testSymbol,
                    'test_file' => $rel->file,
                    'line' => $rel->lineStart,
                    'target_symbol' => $targetDisplay,
                    'kind' => 'instantiation',
                ];
            }
        }

        ksort($testCalls, SORT_STRING);
        /** @var list<array{test_symbol: string, test_file: string, line: int, target_symbol: string, kind: string}> $testCallsList */
        $testCallsList = array_values($testCalls);

        // 5. Find companion test files
        /** @var array<string, array{path: string, symbols: int}> $companionFiles */
        $companionFiles = [];

        foreach ($targetFiles as $tf) {
            foreach ($map->likelyTestFiles($tf, 15) as $cf) {
                $companionFiles[$cf->path] = [
                    'path' => $cf->path,
                    'symbols' => count($cf->symbols),
                ];
            }

            // Also search by stripped base name in all test files
            $baseName = (string) preg_replace('/(?:Test|Cest|\.php)$/i', '', basename($tf->path));
            if ($baseName !== '') {
                $baseLower = strtolower($baseName);
                foreach ($map->files as $cf) {
                    if (!$map->looksLikeTestPath($cf->path) || isset($companionFiles[$cf->path])) {
                        continue;
                    }
                    $candidateBase = strtolower(pathinfo($cf->path, PATHINFO_FILENAME));
                    if (str_contains($candidateBase, $baseLower)) {
                        $companionFiles[$cf->path] = [
                            'path' => $cf->path,
                            'symbols' => count($cf->symbols),
                        ];
                    }
                }
            }
        }

        // Also add any test files that directly called target symbols if not yet in companion list
        foreach ($testCallsList as $tc) {
            if (!isset($companionFiles[$tc['test_file']])) {
                $cf = $filesByPath[$tc['test_file']] ?? null;
                $companionFiles[$tc['test_file']] = [
                    'path' => $tc['test_file'],
                    'symbols' => $cf !== null ? count($cf->symbols) : 1,
                ];
            }
        }

        ksort($companionFiles, SORT_STRING);
        $companionList = array_values($companionFiles);

        // 6. Synthesize suggested test commands
        $suggestedCommands = $this->synthesizeTestCommands($testCallsList, $companionList, $map->root);

        return new TestReport(
            query: $query,
            targetKind: $targetKind,
            targets: $targets,
            testCalls: $testCallsList,
            companionTestFiles: $companionList,
            suggestedTestCommands: $suggestedCommands,
        );
    }

    private function resolveEnclosingTestSymbol(RelationEntry $rel, ?FileEntry $file): string
    {
        if ($rel->sourceId !== '') {
            return $this->cleanTargetSymbolId($rel->sourceId);
        }

        if ($file === null) {
            return $rel->file . ':' . $rel->lineStart;
        }

        foreach ($file->symbols as $symbol) {
            foreach ($symbol->methods as $m) {
                if ($m->lineStart <= $rel->lineStart && $m->lineEnd >= $rel->lineStart) {
                    return $symbol->fqn . '::' . $m->name;
                }
            }
            if ($symbol->lineStart <= $rel->lineStart && $symbol->lineEnd >= $rel->lineStart) {
                return $symbol->fqn;
            }
        }

        return $rel->file . ':' . $rel->lineStart;
    }

    private function cleanTargetSymbolId(string $id): string
    {
        $clean = preg_replace('/^method:/', '', $id);
        $clean = (string) preg_replace('/#m$/', '', (string) $clean);
        $clean = (string) preg_replace('/^type:/', '', $clean);
        return (string) preg_replace('/^func:/', '', $clean);
    }

    /**
     * @param list<array{test_symbol: string, test_file: string, line: int, target_symbol: string, kind: string}> $testCalls
     * @param list<array{path: string, symbols: int}> $companionFiles
     * @return list<string>
     */
    private function synthesizeTestCommands(array $testCalls, array $companionFiles, string $root): array
    {
        $commands = [];
        $hasMakefile = is_file($root . '/Makefile');
        $hasPhpunitXml = is_file($root . '/phpunit.xml') || is_file($root . '/phpunit.xml.dist');

        // Priority 1: Direct test calls with specific test methods
        foreach ($testCalls as $tc) {
            $file = $tc['test_file'];
            $methodPart = null;
            if (str_contains($tc['test_symbol'], '::')) {
                $parts = explode('::', $tc['test_symbol']);
                $methodPart = end($parts);
            }

            if ($hasMakefile && (str_contains($file, '_UnitCest.php') || str_contains($file, 'UnitCest.php'))) {
                $commands[] = 'make test_unit_file FILE=' . $file;
            } elseif (str_contains($file, '_AcceptanceCest.php') || str_contains($file, 'AcceptanceCest.php')) {
                if ($hasMakefile) {
                    $commands[] = 'make test_acceptance';
                }
                $commands[] = 'vendor/bin/codecept run ' . $file;
            } elseif (str_ends_with($file, 'Cest.php')) {
                $commands[] = 'vendor/bin/codecept run ' . $file;
            } elseif ($hasPhpunitXml || str_ends_with($file, 'Test.php')) {
                if ($methodPart !== null && !str_starts_with($methodPart, '__')) {
                    $commands[] = 'vendor/bin/phpunit ' . $file . ' --filter ' . $methodPart;
                }
                $commands[] = 'vendor/bin/phpunit ' . $file;
            }
        }

        // Priority 2: Companion test files
        foreach ($companionFiles as $cf) {
            $file = $cf['path'];
            if ($hasMakefile && (str_contains($file, '_UnitCest.php') || str_contains($file, 'UnitCest.php'))) {
                $commands[] = 'make test_unit_file FILE=' . $file;
            } elseif (str_contains($file, '_AcceptanceCest.php') || str_contains($file, 'AcceptanceCest.php')) {
                if ($hasMakefile) {
                    $commands[] = 'make test_acceptance';
                }
                $commands[] = 'vendor/bin/codecept run ' . $file;
            } elseif (str_ends_with($file, 'Cest.php')) {
                $commands[] = 'vendor/bin/codecept run ' . $file;
            } elseif ($hasPhpunitXml || str_ends_with($file, 'Test.php')) {
                $commands[] = 'vendor/bin/phpunit ' . $file;
            }
        }

        $unique = array_values(array_unique($commands));
        return array_slice($unique, 0, 8);
    }
}
