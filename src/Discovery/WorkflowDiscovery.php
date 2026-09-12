<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Inspect\ScopeInspector;
use voku\AgentMap\Inspect\ScopeTarget;

final class WorkflowDiscovery
{
    public function __construct(
        private readonly TemplateScanner $templateScanner = new TemplateScanner(),
        private readonly ScopeInspector $scopeInspector = new ScopeInspector(),
    ) {
    }

    public function discover(AgentMapIndex $map, string $query): WorkflowReport
    {
        $allTemplates = $this->templateScanner->scan($map->root);

        // Normalize template lookup maps: by relative path, by name, by basename
        $byName = [];
        $byBasename = [];
        foreach ($allTemplates as $relPath => $tpl) {
            $byName[$tpl->name] = $tpl;
            $byBasename[basename($tpl->name)][] = $tpl;
        }

        $isTemplateQuery = str_ends_with($query, '.tpl')
            || str_ends_with($query, '.twig')
            || str_ends_with($query, '.blade.php')
            || isset($byName[$query]);

        $exactSymbol = $this->resolvePhpSymbol($map, $query);

        $targetKind = $isTemplateQuery ? 'template' : ($exactSymbol !== null ? 'symbol' : 'keyword');

        $matchedPhpSymbols = [];
        $matchedTemplates = [];

        if ($targetKind === 'template') {
            $matchedTemplates = $this->findMatchingTemplates($query, $allTemplates, $byName, $byBasename);
            $matchedPhpSymbols = $this->findPhpSymbolsRenderingTemplates($map, $matchedTemplates);
        } elseif ($targetKind === 'symbol' && $exactSymbol !== null) {
            $matchedPhpSymbols[] = $this->describeSymbol($map, $exactSymbol['file'], $exactSymbol['symbol']);
            $matchedTemplates = $this->findTemplatesForSymbols($map, $matchedPhpSymbols, $allTemplates, $byName, $byBasename);
            $extraPhp = $this->findPhpSymbolsRenderingTemplates($map, $matchedTemplates);
            foreach ($extraPhp as $ep) {
                $matchedPhpSymbols[] = $ep;
            }
        } else {
            // Keyword search: search both PHP symbols and templates
            $symbolResult = $map->query($query);
            foreach ($symbolResult->files as $file) {
                foreach ($file->symbols as $sym) {
                    if (stripos($sym->fqn, $query) !== false) {
                        $matchedPhpSymbols[] = $this->describeSymbol($map, $file, $sym);
                    }
                }
            }
            $matchedTemplates = $this->findMatchingTemplates($query, $allTemplates, $byName, $byBasename);
            // Also link templates found from symbols
            $symbolTemplates = $this->findTemplatesForSymbols($map, $matchedPhpSymbols, $allTemplates, $byName, $byBasename);
            foreach ($symbolTemplates as $st) {
                $matchedTemplates[$st->path] = $st;
            }
            // And reverse-link
            $extraPhp = $this->findPhpSymbolsRenderingTemplates($map, $matchedTemplates);
            foreach ($extraPhp as $ep) {
                $matchedPhpSymbols[] = $ep;
            }
        }

        // Deduplicate PHP symbols and separate production from tests
        $uniquePhp = [];
        foreach ($matchedPhpSymbols as $ps) {
            $key = $ps['symbol'] . '@' . $ps['file'];
            $uniquePhp[$key] = $ps;
        }

        $prodSymbols = [];
        $testSymbols = [];
        foreach ($uniquePhp as $ps) {
            if ($map->looksLikeTestPath($ps['file'])) {
                $testSymbols[] = $ps;
            } else {
                $prodSymbols[] = $ps;
            }
        }
        $phpSymbols = $prodSymbols;

        // Find included sub-templates
        $includedTemplates = [];
        foreach ($matchedTemplates as $tpl) {
            foreach ($tpl->includes as $inc) {
                if (isset($byName[$inc])) {
                    $includedTemplates[$byName[$inc]->path] = $byName[$inc];
                } elseif (isset($byBasename[basename($inc)])) {
                    foreach ($byBasename[basename($inc)] as $bMatch) {
                        $includedTemplates[$bMatch->path] = $bMatch;
                    }
                }
            }
        }

        // Find parent templates that include any of the matched templates
        $parentTemplates = [];
        $matchedNames = [];
        foreach ($matchedTemplates as $mt) {
            $matchedNames[$mt->name] = true;
            $matchedNames[basename($mt->name)] = true;
        }
        foreach ($allTemplates as $candPath => $cand) {
            if (isset($matchedTemplates[$candPath])) {
                continue;
            }
            foreach ($cand->includes as $cInc) {
                if (isset($matchedNames[$cInc]) || isset($matchedNames[basename($cInc)])) {
                    $parentTemplates[$candPath] = $cand;
                    break;
                }
            }
        }

        // Resolve Form Actions & Handlers
        $formActions = [];
        $templatesToInspect = [...$matchedTemplates, ...$includedTemplates];
        foreach ($templatesToInspect as $tpl) {
            foreach ($tpl->forms as $form) {
                $action = $form['action'];
                $targetSymbol = null;
                $targetFile = null;

                // Check params for action=... or view=...
                $actionParam = $form['params']['action'] ?? $form['params']['view'] ?? null;
                if ($actionParam !== null) {
                    $symMatch = $this->resolvePhpSymbol($map, $actionParam);
                    if ($symMatch !== null) {
                        $targetSymbol = $symMatch['symbol']->fqn;
                        $targetFile = $symMatch['file']->path;
                    }
                }

                $formActions[] = [
                    'action' => $action,
                    'method' => $form['method'],
                    'target_symbol' => $targetSymbol,
                    'target_file' => $targetFile,
                    'params' => $form['params'],
                ];
            }
        }

        // Resolve Xajax Handlers
        $xajaxHandlers = [];
        foreach ($templatesToInspect as $tpl) {
            foreach ($tpl->xajax as $xj) {
                $cleanXj = str_starts_with($xj, 'xajax_') ? substr($xj, 6) : $xj;
                $targetSymbol = null;
                $targetFile = null;

                $clsMatch = $this->resolvePhpSymbol($map, $cleanXj) ?? $this->resolvePhpSymbol($map, $xj);
                if ($clsMatch !== null) {
                    $targetSymbol = $clsMatch['symbol']->fqn;
                    $targetFile = $clsMatch['file']->path;
                }

                $xajaxHandlers[] = [
                    'call' => $xj,
                    'target_symbol' => $targetSymbol,
                    'target_file' => $targetFile,
                ];
            }
        }

        // Synthesize Flow Steps
        $flowSteps = $this->synthesizeFlow($phpSymbols, $matchedTemplates, $includedTemplates, $formActions, $xajaxHandlers);

        return new WorkflowReport(
            query: $query,
            targetKind: $targetKind,
            phpSymbols: $phpSymbols,
            testSymbols: $testSymbols,
            templates: array_values($matchedTemplates),
            includedTemplates: array_values($includedTemplates),
            parentTemplates: array_values($parentTemplates),
            formActions: $formActions,
            xajaxHandlers: $xajaxHandlers,
            flowSteps: $flowSteps,
        );
    }

    /**
     * @param array<string, TemplateInfo> $allTemplates
     * @param array<string, TemplateInfo> $byName
     * @param array<string, list<TemplateInfo>> $byBasename
     * @return array<string, TemplateInfo>
     */
    private function findMatchingTemplates(string $query, array $allTemplates, array $byName, array $byBasename): array
    {
        $matches = [];

        if (isset($allTemplates[$query])) {
            $matches[$query] = $allTemplates[$query];
            return $matches;
        }

        if (isset($byName[$query])) {
            $matches[$byName[$query]->path] = $byName[$query];
            return $matches;
        }

        $base = basename($query);
        if (isset($byBasename[$base])) {
            foreach ($byBasename[$base] as $m) {
                $matches[$m->path] = $m;
            }
            return $matches;
        }

        // Substring match
        foreach ($allTemplates as $path => $tpl) {
            if (stripos($path, $query) !== false || stripos($tpl->name, $query) !== false) {
                $matches[$path] = $tpl;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, TemplateInfo> $templates
     * @return list<array{symbol: string, file: string, line: int, kind: string, templates: list<string>}>
     */
    private function findPhpSymbolsRenderingTemplates(AgentMapIndex $map, array $templates): array
    {
        $results = [];
        $templateNames = [];
        foreach ($templates as $tpl) {
            $templateNames[$tpl->name] = true;
            $templateNames[basename($tpl->name)] = true;
            $templateNames[$tpl->path] = true;
        }

        foreach ($map->files as $file) {
            $source = @file_get_contents(rtrim($map->root, '/') . '/' . $file->path);
            if (!is_string($source)) {
                continue;
            }

            // Quick check if file mentions any template name
            $mentions = false;
            foreach (array_keys($templateNames) as $tName) {
                if (str_contains($source, $tName)) {
                    $mentions = true;
                    break;
                }
            }

            if (!$mentions) {
                continue;
            }

            foreach ($file->symbols as $symbol) {
                $described = $this->describeSymbol($map, $file, $symbol);
                $relevantTemplates = [];
                foreach ($described['templates'] as $t) {
                    if (isset($templateNames[$t]) || isset($templateNames[basename($t)])) {
                        $relevantTemplates[] = $t;
                    }
                }
                if ($relevantTemplates === [] && $map->looksLikeTestPath($file->path)) {
                    foreach (array_keys($templateNames) as $tName) {
                        if (str_contains($source, $tName)) {
                            $relevantTemplates[] = $tName;
                        }
                    }
                }
                if ($relevantTemplates !== []) {
                    $results[] = [
                        ...$described,
                        'templates' => array_values(array_unique($relevantTemplates)),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * @param list<array{symbol: string, file: string, line: int, kind: string, templates: list<string>}> $symbols
     * @param array<string, TemplateInfo> $allTemplates
     * @param array<string, TemplateInfo> $byName
     * @param array<string, list<TemplateInfo>> $byBasename
     * @return array<string, TemplateInfo>
     */
    private function findTemplatesForSymbols(AgentMapIndex $map, array $symbols, array $allTemplates, array $byName, array $byBasename): array
    {
        $matched = [];

        foreach ($symbols as $sym) {
            foreach ($sym['templates'] as $tPath) {
                if (str_starts_with($tPath, 'inline:')) {
                    continue;
                }
                if (isset($allTemplates[$tPath])) {
                    $matched[$tPath] = $allTemplates[$tPath];
                } elseif (isset($byName[$tPath])) {
                    $matched[$byName[$tPath]->path] = $byName[$tPath];
                } elseif (isset($byBasename[basename($tPath)])) {
                    foreach ($byBasename[basename($tPath)] as $bm) {
                        $matched[$bm->path] = $bm;
                    }
                }
            }
            // Also match templates that invoke this symbol via AJAX or form actions
            $symFqn = $sym['symbol'];
            $symBase = basename(str_replace('\\', '/', $symFqn));
            foreach ($allTemplates as $tPath => $tpl) {
                foreach ($tpl->xajax as $handler) {
                    $clean = preg_replace('/^xajax_/', '', $handler);
                    if ($clean === $symBase || $handler === $symBase || str_contains($handler, $symBase)) {
                        $matched[$tPath] = $tpl;
                    }
                }
                foreach ($tpl->forms as $form) {
                    if (isset($form['action']) && str_contains($form['action'], $symBase)) {
                        $matched[$tPath] = $tpl;
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * @return array{symbol: string, file: string, line: int, kind: string, templates: list<string>}
     */
    private function describeSymbol(AgentMapIndex $map, FileEntry $file, SymbolEntry $symbol): array
    {
        $templates = [];

        foreach ($symbol->methods as $method) {
            try {
                $target = new ScopeTarget(
                    kind: 'method',
                    file: $file->path,
                    label: $symbol->fqn . '::' . $method->name,
                    lineStart: $method->lineStart,
                    lineEnd: $method->lineEnd,
                    sourceId: $symbol->methodId($method),
                );
                $inspection = $this->scopeInspector->inspect($map, $target, 20);
                foreach ($inspection->templates as $th) {
                    $templates[] = $th->path;
                }
            } catch (\Throwable) {
                // Ignore inspection error on individual method
            }
        }

        $templates = array_values(array_unique($templates));

        return [
            'symbol' => $symbol->fqn,
            'file' => $file->path,
            'line' => $symbol->lineStart,
            'kind' => $symbol->kind,
            'templates' => $templates,
        ];
    }

    /**
     * @param list<array{symbol: string, file: string, line: int, kind: string, templates: list<string>}> $phpSymbols
     * @param array<string, TemplateInfo> $templates
     * @param array<string, TemplateInfo> $includedTemplates
     * @param list<array{action: ?string, method: ?string, target_symbol: ?string, target_file: ?string, params: array<string, string>}> $formActions
     * @param list<array{call: string, target_symbol: ?string, target_file: ?string}> $xajaxHandlers
     * @return list<string>
     */
    private function synthesizeFlow(array $phpSymbols, array $templates, array $includedTemplates, array $formActions, array $xajaxHandlers): array
    {
        $steps = [];

        if ($phpSymbols !== []) {
            $views = array_map(static fn ($s): string => $s['symbol'] . ' (' . $s['file'] . ')', $phpSymbols);
            $steps[] = '1. Entrypoint / View: ' . implode(', ', array_slice($views, 0, 3));
        }

        if ($templates !== []) {
            $tplNames = array_map(static fn (TemplateInfo $t): string => $t->name . ' [' . $t->engine . ']', $templates);
            $steps[] = '2. Primary Template: ' . implode(', ', array_slice($tplNames, 0, 3));
        }

        if ($includedTemplates !== []) {
            $incNames = array_map(static fn (TemplateInfo $t): string => $t->name, $includedTemplates);
            $steps[] = '3. Sub-Templates / Partials: ' . implode(', ', array_slice($incNames, 0, 5));
        }

        if ($formActions !== []) {
            $actions = [];
            foreach ($formActions as $fa) {
                if ($fa['target_symbol'] !== null) {
                    $actions[] = ($fa['action'] ?? '') . ' -> ' . $fa['target_symbol'];
                } elseif ($fa['action'] !== null) {
                    $actions[] = $fa['action'];
                }
            }
            if ($actions !== []) {
                $steps[] = '4. Form Actions: ' . implode(', ', array_slice(array_unique($actions), 0, 3));
            }
        }

        if ($xajaxHandlers !== []) {
            $handlers = [];
            foreach ($xajaxHandlers as $xh) {
                $handlers[] = $xh['call'] . ($xh['target_symbol'] !== null ? ' -> ' . $xh['target_symbol'] : '');
            }
            $steps[] = '5. AJAX Handlers: ' . implode(', ', array_slice(array_unique($handlers), 0, 5));
        }

        return $steps;
    }

    /**
     * @return array{file: FileEntry, symbol: SymbolEntry}|null
     */
    private function resolvePhpSymbol(AgentMapIndex $map, string $name): ?array
    {
        $match = $map->symbolById('class:' . $name)
            ?? $map->symbolById('interface:' . $name)
            ?? $map->symbolById('function:' . $name);

        if ($match !== null) {
            return $match;
        }

        $cleanName = ltrim($name, '\\');
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                $shortName = str_contains($symbol->fqn, '\\')
                    ? substr($symbol->fqn, (int) strrpos($symbol->fqn, '\\') + 1)
                    : $symbol->fqn;
                if ($shortName === $cleanName) {
                    return ['file' => $file, 'symbol' => $symbol];
                }
            }
        }

        return null;
    }
}
