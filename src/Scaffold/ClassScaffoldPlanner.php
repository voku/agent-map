<?php

declare(strict_types=1);

namespace voku\AgentMap\Scaffold;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Move\Psr4AutoloadMap;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Plan\ProjectRelativePath;

final readonly class ClassScaffoldPlanner
{
    private const NOT_OBSERVABLE = [
        'Class scaffold generates static declarations; runtime autoload registration and dynamic instantiation are not observable.',
    ];

    private const ALLOWED_TYPES = ['class', 'interface', 'trait', 'enum'];

    public function __construct(private ?string $root = null)
    {
    }

    /**
     * @param list<string> $implements
     */
    public function plan(
        AgentMapIndex $map,
        string $fqn,
        string $type = 'class',
        bool $final = true,
        bool $readonly = false,
        ?string $extends = null,
        array $implements = [],
        ?string $docSummary = null,
        ?string $destinationPath = null,
    ): ClassScaffoldPlan {
        $root = $this->root ?? $map->root;
        $cleanFqn = trim($fqn, '\\');
        if ($cleanFqn === '') {
            return $this->blockedPlan('', '', 'FQN must not be empty.', $map);
        }

        $type = strtolower(trim($type));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->blockedPlan(
                $cleanFqn,
                '',
                sprintf('Invalid type "%s"; must be one of: %s.', $type, implode(', ', self::ALLOWED_TYPES)),
                $map,
            );
        }

        if ($type !== 'class') {
            $final = false;
            $readonly = false;
        }

        // Check if symbol already exists in map
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (strcasecmp(ltrim($symbol->fqn, '\\'), $cleanFqn) === 0) {
                    return $this->blockedPlan(
                        $cleanFqn,
                        $destinationPath ?? '',
                        sprintf('Symbol %s already exists in index (%s).', $cleanFqn, $file->path),
                        $map,
                    );
                }
            }
        }

        // Resolve destination path
        if ($destinationPath !== null && $destinationPath !== '') {
            if (!ProjectRelativePath::isSafe($destinationPath)) {
                return $this->blockedPlan(
                    $cleanFqn,
                    $destinationPath,
                    'Destination path must be a safe project-relative path: ' . $destinationPath,
                    $map,
                );
            }
        } else {
            try {
                $autoloadMap = Psr4AutoloadMap::forProject($root);
            } catch (RuntimeException $e) {
                return $this->blockedPlan($cleanFqn, '', 'Could not load PSR-4 map: ' . $e->getMessage(), $map);
            }

            $candidates = $autoloadMap->candidates($cleanFqn);
            if ($candidates === []) {
                return $this->blockedPlan(
                    $cleanFqn,
                    '',
                    sprintf('No declared PSR-4 mapping matches FQN "%s". Provide an explicit destination path.', $cleanFqn),
                    $map,
                );
            }

            $destinationPath = $candidates[0]->pathFor($cleanFqn);
        }

        $fullPath = rtrim(str_replace('\\', '/', $root), '/') . '/' . ltrim($destinationPath, '/');
        if (is_file($fullPath)) {
            return $this->blockedPlan(
                $cleanFqn,
                $destinationPath,
                sprintf('Destination file already exists: %s', $destinationPath),
                $map,
            );
        }

        // Split namespace and short class name
        $pos = strrpos($cleanFqn, '\\');
        if ($pos === false) {
            $namespace = '';
            $shortName = $cleanFqn;
        } else {
            $namespace = substr($cleanFqn, 0, $pos);
            $shortName = substr($cleanFqn, $pos + 1);
        }

        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $shortName)) {
            return $this->blockedPlan(
                $cleanFqn,
                $destinationPath,
                sprintf('Invalid class name "%s".', $shortName),
                $map,
            );
        }

        $useLocator = new UseStatementLocator();
        $imports = [];

        // Handle extends
        $extendsClause = '';
        if ($extends !== null && trim($extends) !== '') {
            $extClean = trim($extends, '\\');
            $extPos = strrpos($extClean, '\\');
            $extNs = $extPos !== false ? substr($extClean, 0, $extPos) : '';
            $extShort = $extPos !== false ? substr($extClean, $extPos + 1) : $extClean;
            if ($extNs !== $namespace && !$useLocator->isBuiltinType($extClean)) {
                $imports[] = $extClean;
            }
            $extendsClause = ' extends ' . $extShort;
        }

        // Handle implements
        $implementsClause = '';
        if ($implements !== []) {
            $impShortNames = [];
            foreach ($implements as $imp) {
                $impClean = trim($imp, '\\');
                if ($impClean === '') {
                    continue;
                }
                $impPos = strrpos($impClean, '\\');
                $impNs = $impPos !== false ? substr($impClean, 0, $impPos) : '';
                $impShort = $impPos !== false ? substr($impClean, $impPos + 1) : $impClean;
                if ($impNs !== $namespace && !$useLocator->isBuiltinType($impClean)) {
                    $imports[] = $impClean;
                }
                $impShortNames[] = $impShort;
            }
            if ($impShortNames !== []) {
                $implementsClause = ($type === 'interface' ? ' extends ' : ' implements ') . implode(', ', $impShortNames);
            }
        }

        // Build file content
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
        ];

        if ($namespace !== '') {
            $lines[] = 'namespace ' . $namespace . ';';
            $lines[] = '';
        }

        $uniqueImports = array_values(array_unique($imports));
        sort($uniqueImports);
        if ($uniqueImports !== []) {
            foreach ($uniqueImports as $imp) {
                $lines[] = 'use ' . $imp . ';';
            }
            $lines[] = '';
        }

        if ($docSummary !== null && trim($docSummary) !== '') {
            $lines[] = '/**';
            $lines[] = ' * ' . trim($docSummary);
            $lines[] = ' */';
        }

        $modifiers = '';
        if ($type === 'class') {
            if ($final) {
                $modifiers .= 'final ';
            }
            if ($readonly) {
                $modifiers .= 'readonly ';
            }
        }

        $lines[] = $modifiers . $type . ' ' . $shortName . $extendsClause . $implementsClause;
        $lines[] = '{';
        $lines[] = '}';
        $lines[] = '';

        $fileContent = implode("\n", $lines);

        // Verify syntax of generated file
        $verifier = new PhpSyntaxVerifier();
        $syntaxError = $verifier->verifySource($fileContent);
        if ($syntaxError !== null) {
            return $this->blockedPlan(
                $cleanFqn,
                $destinationPath,
                'Generated code failed syntax verification: ' . $syntaxError,
                $map,
            );
        }

        $edits = [
            new PlanEdit(
                $destinationPath,
                '',
                0,
                0,
                1,
                1,
                '',
                $fileContent,
                'file_creation',
                $cleanFqn,
                'scaffold_class',
            ),
        ];

        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        return new ClassScaffoldPlan(
            status: PlanStatus::SAFE,
            targetId: 'class:' . $cleanFqn,
            fqn: $cleanFqn,
            destinationPath: $destinationPath,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            edits: $edits,
            blindSpots: [],
            staleEvidence: $stale,
            blockers: [],
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    private function blockedPlan(string $fqn, string $destinationPath, string $blocker, AgentMapIndex $map): ClassScaffoldPlan
    {
        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        return new ClassScaffoldPlan(
            status: PlanStatus::BLOCKED,
            targetId: 'class:' . $fqn,
            fqn: $fqn,
            destinationPath: $destinationPath,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            edits: [],
            blindSpots: [],
            staleEvidence: $stale,
            blockers: [$blocker],
            notObservable: self::NOT_OBSERVABLE,
        );
    }
}
