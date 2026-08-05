<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use voku\AgentMap\Index\AgentMapIndex;

/**
 * Fuses the structural and lexical channels with weighted Reciprocal Rank Fusion.
 *
 * The semantic channel is not here yet, and its absence is reported rather than hidden: `degraded`
 * plus a reason travels in every result set. A silent fallback would make the first benchmark that
 * compares channels measure something other than what it claims to.
 *
 * Structural hits come from the canonical map and stay authoritative - they are not "rank 1 because
 * the ranking said so", they are facts the query planner recognised as symbol-shaped.
 */
final readonly class HybridSearch
{
    private const int RRF_K = 60;

    /** @var array<string, float> */
    private const array WEIGHTS = [
        'structural' => 1.0,
        'lexical'    => 1.0,
        'semantic'   => 1.0,
    ];

    public function __construct(
        private QueryPlanner $planner = new QueryPlanner(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(AgentMapIndex $index, SearchIndexStore $store, string $query, int $limit = 10): array
    {
        $plan = $this->planner->plan($query);

        $channels = [
            'structural' => $this->structuralRanks($index, $plan['structural_terms'], $limit),
            'lexical'    => $this->lexicalRanks($store, $query, $limit * 3),
        ];

        $rows = [];
        foreach ($store->searchLexical($query, $limit * 3) as $row) {
            $rows[$row['chunk_id']] = $row;
        }

        $scored = [];
        foreach ($channels as $channel => $ranks) {
            foreach ($ranks as $chunkId => $rank) {
                $scored[$chunkId] ??= ['score' => 0.0, 'ranks' => ['structural' => null, 'lexical' => null, 'semantic' => null]];
                $scored[$chunkId]['score'] += self::WEIGHTS[$channel] / (self::RRF_K + $rank);
                $scored[$chunkId]['ranks'][$channel] = $rank;
            }
        }

        $results = [];
        foreach ($scored as $chunkId => $entry) {
            $row = $rows[$chunkId] ?? $this->structuralRow($index, $chunkId);
            if ($row === null) {
                continue;
            }

            $reasons = [];
            foreach ($entry['ranks'] as $channel => $rank) {
                if ($rank !== null) {
                    $reasons[] = $channel . '_rank:' . $rank;
                }
            }

            $results[] = [
                'chunk_id'       => $chunkId,
                'symbol_id'      => $row['symbol_id'],
                'file_path'      => $row['file_path'],
                'start_line'     => $row['start_line'],
                'end_line'       => $row['end_line'],
                'rrf_score'      => round($entry['score'], 6),
                'channel_ranks'  => $entry['ranks'],
                'content_sha256' => $row['content_sha256'],
                'reasons'        => $reasons,
            ];
        }

        // Deterministic to the last field: equal scores must not reorder between two runs, or a
        // recorded benchmark stops being reproducible.
        usort($results, static function (array $left, array $right): int {
            return $right['rrf_score'] <=> $left['rrf_score']
                ?: self::bestRank($left) <=> self::bestRank($right)
                ?: strcmp($left['chunk_id'], $right['chunk_id']);
        });

        return [
            'schema_version'        => '1.0',
            'query'                 => $query,
            'mode'                  => 'hybrid',
            'effective_mode'        => 'structural+lexical',
            'degraded'              => true,
            'degraded_reason'       => 'semantic_channel_not_implemented',
            'map_snapshot'          => $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest,
            'search_index_snapshot' => $store->meta('map_snapshot') ?? 'sha256:none',
            'structural_terms'      => $plan['structural_terms'],
            'results'               => array_slice($results, 0, $limit),
        ];
    }

    /** @param array<string, mixed> $result */
    private static function bestRank(array $result): int
    {
        $best = PHP_INT_MAX;
        /** @var array<string, int|null> $ranks */
        $ranks = $result['channel_ranks'];
        foreach ($ranks as $rank) {
            if ($rank !== null && $rank < $best) {
                $best = $rank;
            }
        }

        return $best;
    }

    /**
     * @param list<string> $terms
     *
     * @return array<string, int>
     */
    private function structuralRanks(AgentMapIndex $index, array $terms, int $limit): array
    {
        $ranks = [];
        $rank = 1;
        foreach ($terms as $term) {
            foreach ($index->query($term)->files as $file) {
                foreach ($file->symbols as $symbol) {
                    $chunkId = CodeChunk::identity($symbol->id(), CodeChunk::KIND_SYMBOL_OVERVIEW);
                    if (!isset($ranks[$chunkId])) {
                        $ranks[$chunkId] = $rank++;
                    }
                    foreach ($symbol->methods as $method) {
                        $methodChunk = CodeChunk::identity($symbol->methodId($method), CodeChunk::KIND_METHOD_BODY);
                        if (!isset($ranks[$methodChunk])) {
                            $ranks[$methodChunk] = $rank++;
                        }
                    }
                    if ($rank > $limit) {
                        return $ranks;
                    }
                }
            }
        }

        return $ranks;
    }

    /** @return array<string, int> */
    private function lexicalRanks(SearchIndexStore $store, string $query, int $limit): array
    {
        $ranks = [];
        $rank = 1;
        foreach ($store->searchLexical($query, $limit) as $row) {
            $ranks[$row['chunk_id']] = $rank++;
        }

        return $ranks;
    }

    /**
     * A structural hit for a chunk the lexical index does not hold - a symbol whose file was never
     * indexed, for instance. The map still knows where it is, so the result stays usable.
     *
     * @return array<string, mixed>|null
     */
    private function structuralRow(AgentMapIndex $index, string $chunkId): ?array
    {
        $symbolId = substr($chunkId, 0, (int)strrpos($chunkId, '#'));
        foreach ($index->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->id() === $symbolId) {
                    return [
                        'symbol_id'      => $symbolId,
                        'file_path'      => $file->path,
                        'start_line'     => $symbol->lineStart,
                        'end_line'       => $symbol->lineEnd,
                        'content_sha256' => $file->sha256,
                    ];
                }
                foreach ($symbol->methods as $method) {
                    if ($symbol->methodId($method) === $symbolId) {
                        return [
                            'symbol_id'      => $symbolId,
                            'file_path'      => $file->path,
                            'start_line'     => $method->lineStart,
                            'end_line'       => $method->lineEnd,
                            'content_sha256' => $file->sha256,
                        ];
                    }
                }
            }
        }

        return null;
    }
}
