<?php

declare(strict_types=1);

namespace voku\AgentMap\Scaffold;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Move\MethodMoveNodeLocator;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Plan\PlanStatus;

final readonly class MethodScaffoldPlanner
{
    private const NOT_OBSERVABLE = [
        'Method scaffold generates static declarations; runtime reflection and dynamic method calls are not observable.',
    ];

    public function __construct(private ?string $root = null)
    {
    }

    /**
     * @param list<string> $parameters e.g. ['string $id', 'int $count = 1']
     */
    public function plan(
        AgentMapIndex $map,
        string $target,
        string $visibility = 'public',
        bool $static = false,
        array $parameters = [],
        ?string $returnType = null,
        ?string $docSummary = null,
        ?string $body = null,
    ): MethodScaffoldPlan {
        $root = $this->root ?? $map->root;
        $target = ltrim(trim($target), '\\');
        $pos = strrpos($target, '::');
        if ($pos === false) {
            return $this->blockedPlan($target, '', 'Target must use Class::method syntax: ' . $target, $map);
        }

        $className = substr($target, 0, $pos);
        $methodName = substr($target, $pos + 2);
        if ($className === '' || $methodName === '') {
            return $this->blockedPlan($className, $methodName, 'Class and method name must be non-empty.', $map);
        }

        $classMatch = $this->resolveClass($map, $className);
        if ($classMatch === null) {
            return $this->blockedPlan($className, $methodName, 'Target class not found in index: ' . $className, $map);
        }
        [$symbol, $file] = $classMatch;

        foreach ($symbol->methods as $existing) {
            if (strcasecmp($existing->name, $methodName) === 0) {
                return $this->blockedPlan($symbol->fqn, $methodName, sprintf('Method %s already exists on %s.', $methodName, $symbol->fqn), $map);
            }
        }

        $filePath = rtrim(str_replace('\\', '/', $root), '/') . '/' . ltrim($file->path, '/');
        if (!is_file($filePath)) {
            return $this->blockedPlan($symbol->fqn, $methodName, 'Source file not found: ' . $file->path, $map);
        }
        $source = file_get_contents($filePath);
        if (!is_string($source)) {
            return $this->blockedPlan($symbol->fqn, $methodName, 'Could not read source file: ' . $file->path, $map);
        }

        $locator = new MethodMoveNodeLocator($root);
        try {
            $anchor = $locator->insertionAnchor($file->path, $symbol->lineStart, $symbol->lineEnd, $symbol->fqn);
        } catch (RuntimeException $e) {
            return $this->blockedPlan($symbol->fqn, $methodName, 'Could not locate insertion anchor: ' . $e->getMessage(), $map);
        }

        $edits = [];
        $blindSpots = [];
        $useLocator = new UseStatementLocator();

        // Check types for use statement insertion
        $typesToCheck = [];
        if ($returnType !== null) {
            $cleanRet = ltrim(trim($returnType), '?');
            if (!$useLocator->isBuiltinType($cleanRet)) {
                $typesToCheck[] = $cleanRet;
            }
        }
        foreach ($parameters as $param) {
            if (preg_match('/^\??([A-Za-z0-9_\\\\]+)\s+\$/', trim($param), $matches) === 1) {
                if (!$useLocator->isBuiltinType($matches[1])) {
                    $typesToCheck[] = $matches[1];
                }
            }
        }

        foreach (array_unique($typesToCheck) as $typeFqn) {
            $useInsertion = $useLocator->findUseInsertion($source, $typeFqn);
            if ($useInsertion !== null) {
                $edits[] = new PlanEdit(
                    $file->path,
                    $file->sha256,
                    $useInsertion['start'],
                    $useInsertion['end'],
                    $useInsertion['line'],
                    $useInsertion['line'],
                    $useInsertion['expected'],
                    $useInsertion['insertion'],
                    'use_statement_insertion',
                    'use:' . $typeFqn,
                    'scaffold_dependency',
                );
            }
        }

        // Build docblock
        $docblock = $this->buildDocblock($docSummary ?? 'Scaffolded method.', $parameters, $returnType);

        // Build method body
        $methodBody = $body !== null ? trim($body) : $this->defaultBody($returnType, $methodName);
        $indentedBody = implode("\n", array_map(static fn (string $l): string => '        ' . $l, explode("\n", $methodBody)));

        $visibility = in_array(strtolower($visibility), ['public', 'protected', 'private'], true) ? strtolower($visibility) : 'public';
        $staticModifier = $static ? 'static ' : '';
        $retTypeDeclaration = $returnType !== null ? ': ' . $returnType : '';
        $paramList = implode(', ', $parameters);

        $methodCode = "    {$docblock}\n    {$visibility} {$staticModifier}function {$methodName}({$paramList}){$retTypeDeclaration}\n    {\n{$indentedBody}\n    }\n";

        $edits[] = new PlanEdit(
            $file->path,
            $file->sha256,
            $anchor['position'],
            $anchor['position'] + strlen($anchor['expected_anchor']) - 1,
            $anchor['line'],
            $anchor['line'],
            $anchor['expected_anchor'],
            "\n" . $methodCode . "\n" . $anchor['expected_anchor'],
            'method_declaration_insertion',
            $symbol->fqn . '::' . $methodName,
            'scaffold_declaration',
        );

        // Verify syntax of simulated edit
        $verifier = new PhpSyntaxVerifier();
        $syntaxError = $verifier->verifyEdits($source, $edits);
        if ($syntaxError !== null) {
            return $this->blockedPlan($symbol->fqn, $methodName, 'Syntax check failed on generated code: ' . $syntaxError, $map);
        }

        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        return new MethodScaffoldPlan(
            status: PlanStatus::SAFE,
            targetClass: $symbol->fqn,
            methodName: $methodName,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            edits: $edits,
            blindSpots: $blindSpots,
            staleEvidence: $stale,
            blockers: [],
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    /**
     * @param list<string> $parameters
     */
    private function buildDocblock(string $summary, array $parameters, ?string $returnType): string
    {
        $lines = ['/**'];
        $lines[] = '     * ' . $summary;
        if ($parameters !== [] || $returnType !== null) {
            $lines[] = '     *';
        }
        foreach ($parameters as $param) {
            if (preg_match('/^(\S+)\s+(\$\S+)/', trim($param), $matches) === 1) {
                $lines[] = sprintf('     * @param %s %s', $matches[1], $matches[2]);
            }
        }
        if ($returnType !== null && $returnType !== 'void') {
            $lines[] = '     * @return ' . $returnType;
        }
        $lines[] = '     */';

        return implode("\n", $lines);
    }

    private function defaultBody(?string $returnType, string $methodName): string
    {
        if ($returnType === 'void') {
            return '// TODO: Implement ' . $methodName . '()';
        }
        if ($returnType === 'bool') {
            return 'return true;';
        }
        if ($returnType === 'int') {
            return 'return 0;';
        }
        if ($returnType === 'string') {
            return "return '';";
        }
        if ($returnType === 'array') {
            return 'return [];';
        }

        return "throw new \\LogicException('Method " . $methodName . " not implemented yet.');";
    }

    /**
     * @return array{SymbolEntry, FileEntry}|null
     */
    private function resolveClass(AgentMapIndex $map, string $target): ?array
    {
        $clean = ltrim($target, '\\');
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (ltrim($symbol->fqn, '\\') === $clean || $symbol->name === $clean) {
                    return [$symbol, $file];
                }
            }
        }

        return null;
    }

    private function blockedPlan(string $targetClass, string $methodName, string $blocker, AgentMapIndex $map): MethodScaffoldPlan
    {
        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        return new MethodScaffoldPlan(
            status: PlanStatus::BLOCKED,
            targetClass: $targetClass,
            methodName: $methodName,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            edits: [],
            blindSpots: [],
            staleEvidence: $stale,
            blockers: [$blocker],
            notObservable: self::NOT_OBSERVABLE,
        );
    }
}
