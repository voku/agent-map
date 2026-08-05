<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Search\Embedding\EmbeddingProvider;

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

    /** Above this many matching files a structural term is treated as vocabulary, not as an identifier. */
    private const int STRUCTURAL_TERM_MAX_FILES = 3;

    /**
     * Nearest-neighbour search always returns k rows, however unrelated they are: asked about
     * espresso machines it will still hand back the ten least-unrelated chunks. Without a cutoff the
     * "no answer" case disappears, which the benchmark caught as the irrelevant category dropping
     * from 1.000 to 0.000. With l2-normalized vectors, unrelated text sits near sqrt(2).
     */
    public const float SEMANTIC_MAX_DISTANCE = 1.0;

    /**
     * Semantic is deliberately the lightest channel.
     *
     * Measured on this package's corpus: at equal weight the semantic channel improved conceptual
     * queries but dragged overall recall@5 from 0.769 down to 0.654, because a vector hit at rank 1
     * outranked the exact lexical answer. Halving it keeps the conceptual gain while leaving the
     * channels that *identify* something in front. The number came from the benchmark, not from
     * taste.
     *
     * @var array<string, float>
     */
    private const array WEIGHTS = [
        'structural' => 1.0,
        'lexical'    => 1.0,
        'semantic'   => 0.5,
    ];

    public function __construct(
        private QueryPlanner $planner = new QueryPlanner(),
        private ?EmbeddingProvider $embeddings = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(AgentMapIndex $index, SearchIndexStore $store, string $query, int $limit = 10): array
    {
        $plan = $this->planner->plan($query);

        $semanticRows = [];
        $semanticRanks = [];
        $degradedReason = 'semantic_channel_unavailable';
        if ($this->embeddings !== null && $store->vectorCount() > 0) {
            $rank = 1;
            foreach ($store->searchSemantic($this->embeddings->embedQuery($query), $this->embeddings->model(), $limit * 3) as $row) {
                if ($row['distance'] > self::SEMANTIC_MAX_DISTANCE) {
                    continue;
                }

                $semanticRows[$row['chunk_id']] = $row;
                $semanticRanks[$row['chunk_id']] = $rank++;
            }
            $degradedReason = null;
        }

        $channels = [
            'structural' => $this->structuralRanks($index, $plan['structural_terms'], $limit),
            'lexical'    => $this->lexicalRanks($store, $query, $limit * 3),
            'semantic'   => $semanticRanks,
        ];

        $rows = $semanticRows;
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
            'effective_mode'        => $degradedReason === null ? 'structural+lexical+semantic' : 'structural+lexical',
            'degraded'              => $degradedReason !== null,
            'degraded_reason'       => $degradedReason,
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
            $files = $index->query($term)->files;
            // A term that matches half the repository is a word, not a symbol. Feeding it into the
            // structural channel adds no identification and pushes the channel that did identify
            // something - usually lexical, on an error message - down the fused ranking.
            if (count($files) > self::STRUCTURAL_TERM_MAX_FILES) {
                continue;
            }

            foreach ($files as $file) {
                foreach ($file->symbols as $symbol) {
                    // Identification, not resemblance: the map's own query is intentionally fuzzy, but a
                    // structural rank claims "this is the symbol you named". An error message that
                    // happens to quote `Class::method` must not manufacture one.
                    if ($this->identifies($term, $symbol->fqn, $symbol->name)) {
                        $chunkId = CodeChunk::identity($symbol->id(), CodeChunk::KIND_SYMBOL_OVERVIEW);
                        if (!isset($ranks[$chunkId])) {
                            $ranks[$chunkId] = $rank++;
                        }
                    }

                    foreach ($symbol->methods as $method) {
                        if (!$this->identifies($term, $symbol->fqn . '::' . $method->name, $method->name)) {
                            continue;
                        }

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

    /**
     * Whether the query term names this symbol: the full name, its short name, or the trailing part
     * of a qualified name. Anything looser is the lexical channel's job.
     */
    private function identifies(string $term, string $qualifiedName, string $shortName): bool
    {
        $term = ltrim($term, '\\');
        $qualifiedName = ltrim($qualifiedName, '\\');

        return strcasecmp($term, $qualifiedName) === 0
            || strcasecmp($term, $shortName) === 0
            || str_ends_with(strtolower($qualifiedName), strtolower('\\' . $term))
            || str_ends_with(strtolower($qualifiedName), strtolower('::' . $term));
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
