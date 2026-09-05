<?php

declare(strict_types=1);

namespace voku\AgentMap\Reconcile;

use voku\AgentMap\Build\SemanticAnalysisResult;
use voku\AgentMap\Index\DiagnosticEntry;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\LocalBindingEntry;
use voku\AgentMap\Index\LocalExitEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\ParameterEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final readonly class MapReconciler
{
    /**
     * @param list<FileEntry> $structuralFiles
     * @return array{files: list<FileEntry>, relations: list<RelationEntry>, diagnostics: list<DiagnosticEntry>, local_bindings: list<LocalBindingEntry>, local_exits: list<LocalExitEntry>}
     */
    public function reconcile(string $root, array $structuralFiles, SemanticAnalysisResult $semantic): array
    {
        $semanticSymbols = [];
        $semanticMethods = [];
        $semanticFunctions = [];
        $semanticRelations = [];
        $semanticBindings = [];
        $semanticExits = [];

        foreach ($semantic->records as $record) {
            $record = $this->normalizeRecord($root, $record);
            if ($record === null) {
                continue;
            }
            $type = $record['record_type'] ?? null;
            if ($type === 'symbol' && is_string($record['name'] ?? null) && is_string($record['kind'] ?? null)) {
                $semanticSymbols[$record['kind'] . ':' . $record['name']] = $record;
            } elseif ($type === 'method' && is_string($record['class'] ?? null) && is_string($record['name'] ?? null)) {
                $semanticMethods['method:' . $record['class'] . '::' . $record['name']] = $record;
            } elseif ($type === 'function' && is_string($record['name'] ?? null)) {
                $semanticFunctions['function:' . $record['name']] = $record;
            } elseif ($type === 'relation') {
                $semanticRelations[] = $record;
            } elseif ($type === 'local_binding') {
                $semanticBindings[] = $record;
            } elseif ($type === 'local_exit') {
                $semanticExits[] = $record;
            }
        }

        $diagnostics = [];
        $files = [];
        $knownSymbolIds = [];
        $knownFqns = [];

        foreach ($structuralFiles as $file) {
            $symbols = [];
            $semanticSeen = false;
            $missingSemanticDiagnostics = [];
            foreach ($file->symbols as $symbol) {
                $semanticRecord = $semanticSymbols[$symbol->id()] ?? null;
                if ($symbol->kind === 'function') {
                    $semanticRecord = $semanticFunctions['function:' . $symbol->fqn] ?? $semanticRecord;
                }
                if (is_array($semanticRecord)) {
                    $semanticSeen = true;
                    $symbol = $this->mergeSymbol($symbol, $semanticRecord, $diagnostics, $file->path);
                } else {
                    $missingSemanticDiagnostics[] = DiagnosticEntry::create(
                        severity: 'warning',
                        kind: 'structural_only_symbol',
                        message: 'Symbol was not present in PHPStan semantic output.',
                        symbolId: $symbol->id(),
                        file: $file->path,
                        line: $symbol->lineStart,
                    );
                }

                $methods = [];
                foreach ($symbol->methods as $method) {
                    $methodId = $symbol->methodId($method);
                    $methodRecord = $semanticMethods[$methodId] ?? null;
                    if (is_array($methodRecord)) {
                        $semanticSeen = true;
                        $method = $this->mergeMethod($method, $methodRecord, $diagnostics, $methodId, $file->path);
                    } else {
                        $missingSemanticDiagnostics[] = DiagnosticEntry::create(
                            severity: 'warning',
                            kind: 'structural_only_method',
                            message: 'Method was not present in PHPStan semantic output.',
                            symbolId: $methodId,
                            file: $file->path,
                            line: $method->lineStart,
                        );
                    }
                    $methods[] = $method;
                    $knownSymbolIds[$methodId] = true;
                }
                $symbol = $this->withMethods($symbol, $methods);
                $symbols[] = $symbol;
                $knownSymbolIds[$symbol->id()] = true;
                $knownFqns[$symbol->fqn] = $symbol->id();
            }

            if (!$semanticSeen && $missingSemanticDiagnostics !== []) {
                $diagnostics[] = DiagnosticEntry::create(
                    severity: 'warning',
                    kind: 'no_semantic_records',
                    message: 'PHPStan produced no semantic records for this file.',
                    file: $file->path,
                );
            } else {
                $diagnostics = [...$diagnostics, ...$missingSemanticDiagnostics];
            }

            $files[$file->path] = new FileEntry(
                path: $file->path,
                sha256: $file->sha256,
                namespace: $file->namespace,
                symbols: $symbols,
                semanticStatus: $semanticSeen ? 'analysed' : 'no_semantic_records',
            );
        }

        // Preserve PHPStan-only source symbols. These are uncommon for physical
        // declarations but useful for extensions, stubs and parser gaps.
        foreach ([...$semanticSymbols, ...$semanticFunctions] as $record) {
            $id = $this->recordId($record);
            if ($id === null || isset($knownSymbolIds[$id])) {
                continue;
            }
            $path = is_string($record['file'] ?? null) ? $record['file'] : '';
            if ($path === '' || !isset($files[$path])) {
                continue;
            }
            $symbol = $this->semanticOnlySymbol($record);
            $symbols = [...$files[$path]->symbols, $symbol];
            usort($symbols, static fn (SymbolEntry $left, SymbolEntry $right): int => $left->lineStart <=> $right->lineStart ?: $left->fqn <=> $right->fqn);
            $files[$path] = new FileEntry($path, $files[$path]->sha256, $files[$path]->namespace, $symbols, 'analysed');
            $knownSymbolIds[$id] = true;
            $knownFqns[$symbol->fqn] = $id;
            $diagnostics[] = DiagnosticEntry::create(
                severity: 'info',
                kind: 'phpstan_only_symbol',
                message: 'PHPStan reported a symbol not present in the structural parser output.',
                symbolId: $id,
                file: $path,
                line: $symbol->lineStart,
            );
        }

        $relations = $this->structuralRelations(array_values($files), $knownFqns);
        foreach ($semanticMethods as $methodId => $record) {
            $prototypeId = $record['prototype_id'] ?? null;
            if (!is_string($prototypeId) || $prototypeId === '') {
                continue;
            }
            $relations[] = RelationEntry::create(
                sourceId: $methodId,
                kind: 'overrides',
                targetIds: [$prototypeId],
                file: (string) ($record['file'] ?? ''),
                lineStart: (int) ($record['line_start'] ?? 0),
                lineEnd: (int) ($record['line_end'] ?? 0),
                resolution: 'phpstan_resolved',
            );
        }

        foreach ($semanticRelations as $record) {
            $targets = [];
            foreach (is_array($record['target_ids'] ?? null) ? $record['target_ids'] : [] as $targetId) {
                if (is_string($targetId) && $targetId !== '') {
                    $targets[] = $targetId;
                }
            }
            if ($targets === []) {
                $targets[] = 'unresolved:' . (string) ($record['kind'] ?? 'relation');
            }
            $sourceId = (string) ($record['source_id'] ?? '');
            if (str_starts_with($sourceId, 'file:')) {
                $sourcePath = substr($sourceId, 5);
                $sourceId = 'file:' . $this->relativePath($root, $sourcePath);
            }
            $relations[] = RelationEntry::create(
                sourceId: $sourceId,
                kind: (string) ($record['kind'] ?? ''),
                targetIds: $targets,
                file: (string) ($record['file'] ?? ''),
                lineStart: (int) ($record['line_start'] ?? 0),
                lineEnd: (int) ($record['line_end'] ?? 0),
                resolution: (string) ($record['resolution'] ?? 'dynamic'),
                receiverType: is_string($record['receiver_type'] ?? null) ? $record['receiver_type'] : null,
                resultType: is_string($record['result_type'] ?? null) ? $record['result_type'] : null,
                startFilePos: is_int($record['start_file_pos'] ?? null) ? $record['start_file_pos'] : null,
                endFilePos: is_int($record['end_file_pos'] ?? null) ? $record['end_file_pos'] : null,
            );
        }

        foreach ($semanticMethods as $methodId => $record) {
            foreach (is_array($record['referenced_classes'] ?? null) ? $record['referenced_classes'] : [] as $className) {
                if (!is_string($className) || $className === '') {
                    continue;
                }
                $relations[] = RelationEntry::create(
                    sourceId: $methodId,
                    kind: 'references_type',
                    targetIds: [$knownFqns[$className] ?? 'type:' . $className],
                    file: (string) ($record['file'] ?? ''),
                    lineStart: (int) ($record['line_start'] ?? 0),
                    lineEnd: (int) ($record['line_end'] ?? 0),
                    resolution: 'phpstan_resolved',
                );
            }
        }
        foreach ($semanticFunctions as $functionId => $record) {
            foreach (is_array($record['referenced_classes'] ?? null) ? $record['referenced_classes'] : [] as $className) {
                if (!is_string($className) || $className === '') {
                    continue;
                }
                $relations[] = RelationEntry::create(
                    sourceId: $functionId,
                    kind: 'references_type',
                    targetIds: [$knownFqns[$className] ?? 'type:' . $className],
                    file: (string) ($record['file'] ?? ''),
                    lineStart: (int) ($record['line_start'] ?? 0),
                    lineEnd: (int) ($record['line_end'] ?? 0),
                    resolution: 'phpstan_resolved',
                );
            }
        }

        foreach ($semantic->findings as $finding) {
            $diagnostics[] = DiagnosticEntry::create('warning', 'phpstan_finding', $finding);
        }

        $localBindings = [];
        foreach ($semanticBindings as $record) {
            $ownerId = (string) ($record['owner_id'] ?? '');
            if (str_starts_with($ownerId, 'file:')) {
                $sourcePath = substr($ownerId, 5);
                $ownerId = 'file:' . $this->relativePath($root, $sourcePath);
            }
            $localBindings[] = LocalBindingEntry::create(
                ownerId: $ownerId,
                variable: (string) ($record['variable'] ?? ''),
                file: (string) ($record['file'] ?? ''),
                lineStart: (int) ($record['line_start'] ?? 0),
                lineEnd: (int) ($record['line_end'] ?? 0),
                resolvedType: (string) ($record['resolved_type'] ?? 'mixed'),
                expressionKind: (string) ($record['expression_kind'] ?? 'other'),
                literalValue: is_string($record['literal_value'] ?? null) ? $record['literal_value'] : null,
                startFilePos: is_int($record['start_file_pos'] ?? null) ? $record['start_file_pos'] : null,
                endFilePos: is_int($record['end_file_pos'] ?? null) ? $record['end_file_pos'] : null,
                rhsStartFilePos: is_int($record['rhs_start_file_pos'] ?? null) ? $record['rhs_start_file_pos'] : null,
                rhsEndFilePos: is_int($record['rhs_end_file_pos'] ?? null) ? $record['rhs_end_file_pos'] : null,
            );
        }
        $localBindings = $this->uniqueBindings($localBindings);

        $localExits = [];
        foreach ($semanticExits as $record) {
            $ownerId = (string) ($record['owner_id'] ?? '');
            if (str_starts_with($ownerId, 'file:')) {
                $sourcePath = substr($ownerId, 5);
                $ownerId = 'file:' . $this->relativePath($root, $sourcePath);
            }
            $localExits[] = LocalExitEntry::create(
                ownerId: $ownerId,
                kind: (string) ($record['kind'] ?? 'return'),
                file: (string) ($record['file'] ?? ''),
                lineStart: (int) ($record['line_start'] ?? 0),
                lineEnd: (int) ($record['line_end'] ?? 0),
                expressionType: (string) ($record['expression_type'] ?? 'void'),
                literalValue: is_string($record['literal_value'] ?? null) ? $record['literal_value'] : null,
                variable: is_string($record['variable'] ?? null) ? $record['variable'] : null,
                startFilePos: is_int($record['start_file_pos'] ?? null) ? $record['start_file_pos'] : null,
                endFilePos: is_int($record['end_file_pos'] ?? null) ? $record['end_file_pos'] : null,
                exprStartFilePos: is_int($record['expr_start_file_pos'] ?? null) ? $record['expr_start_file_pos'] : null,
                exprEndFilePos: is_int($record['expr_end_file_pos'] ?? null) ? $record['expr_end_file_pos'] : null,
            );
        }
        $localExits = $this->uniqueExits($localExits);

        $files = array_values($files);
        usort($files, static fn (FileEntry $left, FileEntry $right): int => $left->path <=> $right->path);
        $relations = $this->uniqueRelations($relations);
        $diagnostics = $this->uniqueDiagnostics($diagnostics);

        return [
            'files' => $files,
            'relations' => $relations,
            'diagnostics' => $diagnostics,
            'local_bindings' => $localBindings,
            'local_exits' => $localExits,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function normalizeRecord(string $root, array $record): ?array
    {
        if (is_string($record['file'] ?? null)) {
            $record['file'] = $this->relativePath($root, $record['file']);
            if (str_starts_with((string) $record['file'], '../')) {
                return null;
            }
        }

        return $record;
    }

    private function relativePath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if ($path === $root) {
            return '.';
        }
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<DiagnosticEntry> $diagnostics
     */
    private function mergeSymbol(SymbolEntry $symbol, array $record, array &$diagnostics, string $path): SymbolEntry
    {
        $kind = (string) ($record['kind'] ?? $symbol->kind);
        $semanticFile = (string) ($record['file'] ?? $path);
        $status = 'confirmed';
        if ($kind !== $symbol->kind || $semanticFile !== $path) {
            $status = 'conflict';
            $diagnostics[] = DiagnosticEntry::create(
                'error',
                'symbol_conflict',
                'Structural and PHPStan symbol identity differ.',
                $symbol->id(),
                $path,
                $symbol->lineStart,
            );
        }

        $structuralExtends = $this->stringList($symbol->extends);
        $structuralImplements = $this->stringList($symbol->implements);
        $structuralUses = $this->stringList($symbol->uses);
        $extends = $this->stringList($record['extends'] ?? $structuralExtends);
        $implements = $this->stringList($record['implements'] ?? $structuralImplements);
        $uses = $this->stringList($record['uses'] ?? $structuralUses);
        $templates = $this->stringMap($record['templates'] ?? []);
        if ($templates !== [] || $extends !== $structuralExtends || $implements !== $structuralImplements || $uses !== $structuralUses) {
            $status = $status === 'conflict' ? $status : 'semantic_enrichment';
        }

        return new SymbolEntry(
            kind: $symbol->kind,
            name: $symbol->name,
            fqn: $symbol->fqn,
            lineStart: $symbol->lineStart,
            lineEnd: $symbol->lineEnd,
            methods: $symbol->methods,
            extends: $extends,
            implements: $implements,
            parameters: $symbol->parameters,
            nativeReturnType: $symbol->nativeReturnType,
            phpDocReturnType: $symbol->phpDocReturnType,
            resolvedReturnType: $symbol->resolvedReturnType,
            attributes: $symbol->attributes,
            uses: $uses,
            templates: $templates,
            reconciliationStatus: $status,
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param list<DiagnosticEntry> $diagnostics
     */
    private function mergeMethod(MethodEntry $method, array $record, array &$diagnostics, string $methodId, string $path): MethodEntry
    {
        $semanticLineStart = (int) ($record['line_start'] ?? $method->lineStart);
        if ($semanticLineStart !== $method->lineStart) {
            $diagnostics[] = DiagnosticEntry::create(
                'info',
                'source_range_mismatch',
                'Structural and PHPStan method start lines differ.',
                $methodId,
                $path,
                $method->lineStart,
            );
        }

        $parameters = [];
        foreach (is_array($record['parameters'] ?? null) ? $record['parameters'] : [] as $parameter) {
            if (is_array($parameter)) {
                $parameters[] = ParameterEntry::fromArray($parameter);
            }
        }
        if ($parameters === []) {
            $parameters = $method->parameters;
        }

        $native = $this->nullableString($record['native_return_type'] ?? null) ?? $method->nativeReturnType;
        $phpDoc = $this->nullableString($record['phpdoc_return_type'] ?? null);
        $resolved = $this->nullableString($record['resolved_return_type'] ?? null);
        $enriched = $phpDoc !== null || ($resolved !== null && $resolved !== $native) || !$this->parametersEqual($parameters, $method->parameters);

        return new MethodEntry(
            name: $method->name,
            visibility: (string) ($record['visibility'] ?? $method->visibility),
            lineStart: $method->lineStart,
            lineEnd: $method->lineEnd,
            static: (bool) ($record['static'] ?? $method->static),
            abstract: (bool) ($record['abstract'] ?? $method->abstract),
            final: (bool) ($record['final'] ?? $method->final),
            parameters: $parameters,
            nativeReturnType: $native,
            phpDocReturnType: $phpDoc,
            resolvedReturnType: $resolved,
            attributes: $method->attributes,
            reconciliationStatus: $enriched ? 'semantic_enrichment' : 'confirmed',
        );
    }

    /** @param list<MethodEntry> $methods */
    private function withMethods(SymbolEntry $symbol, array $methods): SymbolEntry
    {
        return new SymbolEntry(
            kind: $symbol->kind,
            name: $symbol->name,
            fqn: $symbol->fqn,
            lineStart: $symbol->lineStart,
            lineEnd: $symbol->lineEnd,
            methods: $methods,
            extends: $symbol->extends,
            implements: $symbol->implements,
            parameters: $symbol->parameters,
            nativeReturnType: $symbol->nativeReturnType,
            phpDocReturnType: $symbol->phpDocReturnType,
            resolvedReturnType: $symbol->resolvedReturnType,
            attributes: $symbol->attributes,
            uses: $symbol->uses,
            templates: $symbol->templates,
            reconciliationStatus: $symbol->reconciliationStatus,
        );
    }

    /** @param array<string, mixed> $record */
    private function semanticOnlySymbol(array $record): SymbolEntry
    {
        $fqn = (string) ($record['name'] ?? '');
        $shortName = str_contains($fqn, '\\') ? substr($fqn, (int) strrpos($fqn, '\\') + 1) : $fqn;
        $parameters = [];
        foreach (is_array($record['parameters'] ?? null) ? $record['parameters'] : [] as $parameter) {
            if (is_array($parameter)) {
                $parameters[] = ParameterEntry::fromArray($parameter);
            }
        }

        return new SymbolEntry(
            kind: (string) ($record['record_type'] === 'function' ? 'function' : ($record['kind'] ?? 'class')),
            name: $shortName,
            fqn: $fqn,
            lineStart: (int) ($record['line_start'] ?? 0),
            lineEnd: (int) ($record['line_end'] ?? 0),
            extends: $this->stringList($record['extends'] ?? []),
            implements: $this->stringList($record['implements'] ?? []),
            parameters: $parameters,
            nativeReturnType: $this->nullableString($record['native_return_type'] ?? null),
            phpDocReturnType: $this->nullableString($record['phpdoc_return_type'] ?? null),
            resolvedReturnType: $this->nullableString($record['resolved_return_type'] ?? null),
            uses: $this->stringList($record['uses'] ?? []),
            templates: $this->stringMap($record['templates'] ?? []),
            reconciliationStatus: 'phpstan_only',
        );
    }

    /**
     * @param list<FileEntry> $files
     * @param array<string, string> $knownFqns
     * @return list<RelationEntry>
     */
    private function structuralRelations(array $files, array $knownFqns): array
    {
        $relations = [];
        foreach ($files as $file) {
            foreach ($file->symbols as $symbol) {
                $relations[] = RelationEntry::create('file:' . $file->path, 'defines', [$symbol->id()], $file->path, $symbol->lineStart, $symbol->lineEnd, 'structural_only');
                foreach ($symbol->methods as $method) {
                    $relations[] = RelationEntry::create($symbol->id(), 'declares_method', [$symbol->methodId($method)], $file->path, $method->lineStart, $method->lineEnd, 'structural_only');
                }
                foreach ($symbol->extends as $target) {
                    $relations[] = RelationEntry::create($symbol->id(), 'extends', [$this->targetId($target, $knownFqns)], $file->path, $symbol->lineStart, $symbol->lineEnd, 'structural_only');
                }
                foreach ($symbol->implements as $target) {
                    $relations[] = RelationEntry::create($symbol->id(), 'implements', [$this->targetId($target, $knownFqns)], $file->path, $symbol->lineStart, $symbol->lineEnd, 'structural_only');
                }
                foreach ($symbol->uses as $target) {
                    $relations[] = RelationEntry::create($symbol->id(), 'uses_trait', [$this->targetId($target, $knownFqns)], $file->path, $symbol->lineStart, $symbol->lineEnd, 'structural_only');
                }
            }
        }

        return $relations;
    }

    private function baseTypeName(string $type): string
    {
        $position = strpos($type, '<');
        return ltrim($position === false ? $type : substr($type, 0, $position), '\\');
    }

    /** @param array<string, string> $knownFqns */
    private function targetId(string $target, array $knownFqns): string
    {
        $base = $this->baseTypeName($target);

        return $knownFqns[$base] ?? 'type:' . $base;
    }

    /**
     * @param list<ParameterEntry> $left
     * @param list<ParameterEntry> $right
     */
    private function parametersEqual(array $left, array $right): bool
    {
        return array_map(static fn (ParameterEntry $parameter): array => $parameter->toArray(), $left)
            === array_map(static fn (ParameterEntry $parameter): array => $parameter->toArray(), $right);
    }

    /**
     * @param list<RelationEntry> $relations
     * @return list<RelationEntry>
     */
    private function uniqueRelations(array $relations): array
    {
        $unique = [];
        foreach ($relations as $relation) {
            $unique[$relation->id] = $relation;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * @param list<DiagnosticEntry> $diagnostics
     * @return list<DiagnosticEntry>
     */
    private function uniqueDiagnostics(array $diagnostics): array
    {
        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            $unique[$diagnostic->id] = $diagnostic;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * @param list<LocalBindingEntry> $bindings
     * @return list<LocalBindingEntry>
     */
    private function uniqueBindings(array $bindings): array
    {
        $unique = [];
        foreach ($bindings as $binding) {
            $unique[$binding->id] = $binding;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * @param list<LocalExitEntry> $exits
     * @return list<LocalExitEntry>
     */
    private function uniqueExits(array $exits): array
    {
        $unique = [];
        foreach ($exits as $exit) {
            $unique[$exit->id] = $exit;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }


    /** @param array<string, mixed> $record */
    private function recordId(array $record): ?string
    {
        $type = $record['record_type'] ?? null;
        if ($type === 'function' && is_string($record['name'] ?? null)) {
            return 'function:' . $record['name'];
        }
        if ($type === 'symbol' && is_string($record['kind'] ?? null) && is_string($record['name'] ?? null)) {
            return $record['kind'] . ':' . $record['name'];
        }

        return null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = ltrim($item, '\\');
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $result[$key] = $item;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
