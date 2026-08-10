<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use InvalidArgumentException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;

final readonly class ArchitectureDiscovery
{
    private const COUPLING_NAMESPACE = 'namespace';
    private const COUPLING_DIRECTORY = 'directory';
    private const COUPLING_FILE = 'file';

    public function discover(AgentMapIndex $map, int $limit = 10): RepositoryDiscoveryReport
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Discovery limit must be positive.');
        }

        $catalog = new GraphNodeCatalog($map);
        $adjacency = new GraphAdjacency($map);
        $ranker = new GraphRanker();

        $callHubs = $this->mergeRankings([
            $ranker->rank($map, GraphMetric::CALLERS, 'method', $limit),
            $ranker->rank($map, GraphMetric::CALLERS, 'function', $limit),
        ], $limit);
        $orchestrators = $this->mergeRankings([
            $ranker->rank($map, GraphMetric::CALLEES, 'method', $limit),
            $ranker->rank($map, GraphMetric::CALLEES, 'function', $limit),
        ], $limit);
        $typeHubs = $this->mergeRankings([
            $ranker->rank($map, GraphMetric::DEPENDENTS, 'class', $limit),
            $ranker->rank($map, GraphMetric::DEPENDENTS, 'interface', $limit),
            $ranker->rank($map, GraphMetric::DEPENDENTS, 'trait', $limit),
            $ranker->rank($map, GraphMetric::DEPENDENTS, 'enum', $limit),
        ], $limit);

        return new RepositoryDiscoveryReport(
            mapDigest: $map->mapDigest(),
            entrypointCandidates: $this->entrypointCandidates($map, $catalog, $adjacency, $limit),
            callHubs: $callHubs,
            orchestrators: $orchestrators,
            typeHubs: $typeHubs,
            namespaceCoupling: $this->coupling($catalog, $map->relations, $limit, self::COUPLING_NAMESPACE),
            directoryCoupling: $this->coupling($catalog, $map->relations, $limit, self::COUPLING_DIRECTORY),
            fileCoupling: $this->coupling($catalog, $map->relations, $limit, self::COUPLING_FILE),
            quality: $this->quality($map),
        );
    }

    /**
     * Candidates, not assertions: these nodes have no indexed internal caller but
     * do call into the repository. Framework callbacks, reflection and generated
     * dispatch can still make them non-entrypoints, so the report keeps the weaker
     * and falsifiable label.
     *
     * @return list<RankedNode>
     */
    private function entrypointCandidates(
        AgentMapIndex $map,
        GraphNodeCatalog $catalog,
        GraphAdjacency $adjacency,
        int $limit,
    ): array {
        $candidates = [];
        foreach ($catalog->all() as $node) {
            if (!in_array($node->kind, ['method', 'function'], true)) {
                continue;
            }

            if ($node->kind === 'method') {
                $resolved = $map->resolvedMethodById($node->id);
                if ($resolved === null || $resolved->method->abstract) {
                    continue;
                }
            }

            $incomingCallers = $this->uniqueNeighbours($adjacency->incoming($node->id), true, 'calls');
            if ($incomingCallers !== []) {
                continue;
            }

            $outgoing = $adjacency->outgoing($node->id);
            $callees = $this->uniqueNeighbours($outgoing, false, 'calls');
            if ($callees === []) {
                continue;
            }

            $uncertain = [];
            foreach ($outgoing as $relation) {
                if ($relation->kind === 'calls' && $this->isUncertain($relation)) {
                    $uncertain[$relation->id] = true;
                }
            }
            $candidates[] = new RankedNode($node, count($callees), count($uncertain));
        }

        usort($candidates, static function (RankedNode $left, RankedNode $right): int {
            return $right->score <=> $left->score
                ?: $left->uncertainRelations <=> $right->uncertainRelations
                ?: $left->node->id <=> $right->node->id;
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param list<list<RankedNode>> $rankings
     * @return list<RankedNode>
     */
    private function mergeRankings(array $rankings, int $limit): array
    {
        $merged = [];
        foreach ($rankings as $ranking) {
            foreach ($ranking as $row) {
                if ($row->score > 0) {
                    $merged[$row->node->id] = $row;
                }
            }
        }

        $rows = array_values($merged);
        usort($rows, static function (RankedNode $left, RankedNode $right): int {
            return $right->score <=> $left->score
                ?: $left->uncertainRelations <=> $right->uncertainRelations
                ?: $left->node->id <=> $right->node->id;
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * Derive coupling from independent structural axes instead of assuming PHP namespaces exist.
     *
     * Namespace coupling expresses logical package boundaries. Directory coupling keeps useful
     * structure for namespace-less legacy code, while file coupling still works for completely flat
     * projects where every PHP file lives in the same directory.
     *
     * @param list<RelationEntry> $relations
     * @param self::COUPLING_* $dimension
     * @return list<array{from: string, to: string, links: int, uncertain_links: int}>
     */
    private function coupling(GraphNodeCatalog $catalog, array $relations, int $limit, string $dimension): array
    {
        /** @var array<string, array{from: string, to: string, links: array<string, true>, uncertain_links: array<string, true>}> $pairs */
        $pairs = [];
        foreach ($relations as $relation) {
            if (!$this->isDependencyRelation($relation->kind)) {
                continue;
            }

            $source = $catalog->find($relation->sourceId);
            if ($source === null) {
                continue;
            }
            $from = $this->couplingRegion($source, $dimension);
            if ($from === null) {
                continue;
            }

            foreach ($relation->targetIds as $targetId) {
                $target = $catalog->find($targetId);
                if ($target === null) {
                    continue;
                }
                $to = $this->couplingRegion($target, $dimension);
                if ($to === null || $from === $to) {
                    continue;
                }

                $key = $from . "\0" . $to;
                $pairs[$key] ??= [
                    'from' => $from,
                    'to' => $to,
                    'links' => [],
                    'uncertain_links' => [],
                ];
                $link = $relation->sourceId . "\0" . $targetId;
                $pairs[$key]['links'][$link] = true;
                if ($this->isUncertain($relation)) {
                    $pairs[$key]['uncertain_links'][$link] = true;
                }
            }
        }

        $rows = [];
        foreach ($pairs as $pair) {
            $rows[] = [
                'from' => $pair['from'],
                'to' => $pair['to'],
                'links' => count($pair['links']),
                'uncertain_links' => count($pair['uncertain_links']),
            ];
        }
        usort($rows, static function (array $left, array $right): int {
            return $right['links'] <=> $left['links']
                ?: $left['uncertain_links'] <=> $right['uncertain_links']
                ?: $left['from'] <=> $right['from']
                ?: $left['to'] <=> $right['to'];
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param self::COUPLING_* $dimension
     */
    private function couplingRegion(GraphNode $node, string $dimension): ?string
    {
        return match ($dimension) {
            self::COUPLING_NAMESPACE => $this->namespaceOf($node),
            self::COUPLING_DIRECTORY => $this->directoryOf($node),
            self::COUPLING_FILE => $node->file,
            default => throw new InvalidArgumentException('Unknown coupling dimension: ' . $dimension),
        };
    }

    /**
     * @param list<RelationEntry> $relations
     * @return array<string, true>
     */
    private function uniqueNeighbours(array $relations, bool $incoming, string $kind): array
    {
        $neighbours = [];
        foreach ($relations as $relation) {
            if ($relation->kind !== $kind) {
                continue;
            }
            if ($incoming) {
                $neighbours[$relation->sourceId] = true;
                continue;
            }
            foreach ($relation->targetIds as $targetId) {
                if (!str_starts_with($targetId, 'unresolved:')) {
                    $neighbours[$targetId] = true;
                }
            }
        }

        return $neighbours;
    }

    private function namespaceOf(GraphNode $node): ?string
    {
        $name = str_contains($node->name, '::')
            ? substr($node->name, 0, (int) strrpos($node->name, '::'))
            : $node->name;
        $separator = strrpos($name, '\\');

        return $separator === false ? null : substr($name, 0, $separator);
    }

    private function directoryOf(GraphNode $node): string
    {
        $directory = str_replace('\\', '/', dirname($node->file));

        return $directory === '.' ? '(root)' : $directory;
    }

    /**
     * @return array{total_relations: int, certain_relations: int, uncertain_relations: int, dynamic_relations: int, multiple_target_relations: int, diagnostics: int}
     */
    private function quality(AgentMapIndex $map): array
    {
        $dynamic = 0;
        $multiple = 0;
        foreach ($map->relations as $relation) {
            if ($relation->resolution === 'dynamic') {
                ++$dynamic;
            } elseif ($relation->resolution === 'multiple_targets') {
                ++$multiple;
            }
        }
        $uncertain = $dynamic + $multiple;
        $total = count($map->relations);

        return [
            'total_relations' => $total,
            'certain_relations' => $total - $uncertain,
            'uncertain_relations' => $uncertain,
            'dynamic_relations' => $dynamic,
            'multiple_target_relations' => $multiple,
            'diagnostics' => count($map->diagnostics),
        ];
    }

    private function isDependencyRelation(string $kind): bool
    {
        return in_array($kind, [
            'calls',
            'extends',
            'implements',
            'instantiates',
            'overrides',
            'references_type',
            'uses_trait',
        ], true);
    }

    private function isUncertain(RelationEntry $relation): bool
    {
        return in_array($relation->resolution, ['dynamic', 'multiple_targets'], true);
    }
}
