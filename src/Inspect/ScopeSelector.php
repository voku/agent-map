<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;

/**
 * Resolves a `Class::method`, bare function name, or bare class-like name
 * against an already-built AgentMapIndex. Matching is exact (fqn or short
 * name), not the fuzzy substring scoring `AgentMapIndex::query()` uses for
 * discovery — `scope` needs a single unambiguous source range, not a ranked
 * list of hints.
 */
final readonly class ScopeSelector
{
    public function select(AgentMapIndex $index, string $term): ScopeSelection
    {
        $term = trim($term);
        if ($term === '') {
            return new ScopeSelection(null, 'not_found');
        }

        if (str_contains($term, '::')) {
            [$classPart, $methodName] = explode('::', $term, 2);

            return $this->selectMethod($index, ltrim($classPart, '\\'), $methodName);
        }

        return $this->selectFunctionOrClass($index, ltrim($term, '\\'));
    }

    private function selectMethod(AgentMapIndex $index, string $classPart, string $methodName): ScopeSelection
    {
        $classes = $this->matchingClassSymbols($index, $classPart);
        if (count($classes) > 1) {
            return new ScopeSelection(null, 'ambiguous', $this->classCandidateLabels($classes));
        }

        if ($classes === []) {
            $similarClasses = [];
            $classLower = strtolower($classPart);
            foreach ($index->files as $f) {
                foreach ($f->symbols as $s) {
                    if (in_array($s->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                        $sName = str_contains($classPart, '\\') ? $s->fqn : $s->name;
                        $sLower = strtolower($sName);
                        if (levenshtein($classLower, $sLower) <= 3 || str_contains($sLower, $classLower) || str_contains($classLower, $sLower)) {
                            $similarClasses[] = $s->fqn . '::' . $methodName . ' (' . $f->path . ')';
                        }
                    }
                }
            }

            return new ScopeSelection(null, 'not_found', array_slice($similarClasses, 0, 5));
        }

        [$file, $symbol] = $classes[0];
        $methods = array_values(array_filter(
            $symbol->methods,
            static fn (MethodEntry $method): bool => $method->name === $methodName,
        ));

        if (count($methods) > 1) {
            return new ScopeSelection(null, 'ambiguous', array_map(
                static fn (MethodEntry $method): string => $symbol->fqn . '::' . $method->name . ' (' . $file->path . ' #L' . $method->lineStart . ')',
                $methods,
            ));
        }

        if ($methods === []) {
            $available = array_map(static fn (MethodEntry $m): string => $m->name, $symbol->methods);
            sort($available, SORT_STRING);
            $similar = [];
            $methodLower = strtolower($methodName);
            foreach ($available as $mName) {
                $mLower = strtolower($mName);
                if (levenshtein($methodLower, $mLower) <= 3 || str_contains($mLower, $methodLower) || str_contains($methodLower, $mLower)) {
                    $similar[] = $symbol->fqn . '::' . $mName . ' (' . $file->path . ')';
                }
            }

            $candidates = $similar !== []
                ? $similar
                : array_map(
                    static fn (string $m): string => $symbol->fqn . '::' . $m . ' (' . $file->path . ')',
                    array_slice($available, 0, 15),
                );

            return new ScopeSelection(null, 'not_found', $candidates);
        }

        $method = $methods[0];

        return new ScopeSelection(new ScopeTarget(
            'method',
            $symbol->fqn . '::' . $method->name,
            $file->path,
            $method->lineStart,
            $method->lineEnd,
            $symbol->methodId($method),
        ), 'found');
    }

    private function selectFunctionOrClass(AgentMapIndex $index, string $term): ScopeSelection
    {
        $functions = [];
        $classes = [];
        foreach ($index->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->name !== $term && $symbol->fqn !== $term) {
                    continue;
                }

                if ($symbol->kind === 'function') {
                    $functions[] = [$file, $symbol];
                } else {
                    $classes[] = [$file, $symbol];
                }
            }
        }

        if (count($functions) > 1) {
            return new ScopeSelection(null, 'ambiguous', $this->classCandidateLabels($functions));
        }

        if ($functions !== []) {
            [$file, $symbol] = $functions[0];

            return new ScopeSelection(
                new ScopeTarget('function', $symbol->fqn, $file->path, $symbol->lineStart, $symbol->lineEnd, $symbol->id()),
                'found',
            );
        }

        if (count($classes) > 1) {
            return new ScopeSelection(null, 'ambiguous', $this->classCandidateLabels($classes));
        }

        if ($classes !== []) {
            [$file, $symbol] = $classes[0];

            return new ScopeSelection(
                new ScopeTarget($symbol->kind, $symbol->fqn, $file->path, $symbol->lineStart, $symbol->lineEnd),
                'found',
            );
        }

        return new ScopeSelection(null, 'not_found');
    }

    /**
     * @return list<array{0: FileEntry, 1: SymbolEntry}>
     */
    private function matchingClassSymbols(AgentMapIndex $index, string $classPart): array
    {
        $matches = [];
        foreach ($index->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind === 'function') {
                    continue;
                }

                if ($symbol->fqn === $classPart || $symbol->name === $classPart) {
                    $matches[] = [$file, $symbol];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<array{0: FileEntry, 1: SymbolEntry}> $matches
     * @return list<string>
     */
    private function classCandidateLabels(array $matches): array
    {
        return array_map(
            static fn (array $match): string => $match[1]->fqn . ' (' . $match[0]->path . ' #L' . $match[1]->lineStart . ')',
            $matches,
        );
    }
}
