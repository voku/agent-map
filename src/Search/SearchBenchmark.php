<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use JsonException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Measures retrieval quality per channel so that ranking changes are evidence-backed.
 *
 * Every channel answers the same cases, and the report keeps them side by side: a hybrid ranking
 * that is worse than plain lexical on exact-symbol queries is a regression no matter how much
 * better it looks on conceptual ones. Categories exist for exactly that reason - one aggregate
 * number would hide the trade.
 *
 * A case is satisfied when any of its expected symbol ids appears; chunk kinds are not part of the
 * expectation, because whether an answer arrives as the overview or the method body is a chunking
 * decision, not a retrieval failure.
 */
final readonly class SearchBenchmark
{
    private const RECALL_AT = 5;

    private const MRR_AT = 10;

    public function __construct(
        private HybridSearch $hybrid = new HybridSearch(),
        private ?\voku\AgentMap\Search\Embedding\EmbeddingProvider $embeddings = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(AgentMapIndex $index, SearchIndexStore $store, string $casesFile): array
    {
        $cases = $this->load($casesFile);

        $byCategory = [];
        $details = [];
        foreach ($cases as $case) {
            $ranks = [
                'structural' => $this->rankOf($this->structuralHits($index, $case['query']), $case['expected']),
                'lexical'    => $this->rankOf($this->lexicalHits($store, $case['query']), $case['expected']),
                'semantic'   => $this->rankOf($this->semanticHits($store, $case['query']), $case['expected']),
                'hybrid'     => $this->rankOf($this->hybridHits($index, $store, $case['query']), $case['expected']),
            ];

            foreach ($ranks as $channel => $rank) {
                $byCategory[$case['category']][$channel][] = $rank;
                $byCategory['_all'][$channel][] = $rank;
            }

            $details[] = [
                'id'       => $case['id'],
                'category' => $case['category'],
                'query'    => $case['query'],
                'ranks'    => $ranks,
            ];
        }

        $categories = [];
        foreach ($byCategory as $category => $channels) {
            foreach ($channels as $channel => $ranks) {
                $categories[$category][$channel] = [
                    'cases'      => count($ranks),
                    'recall_at_5' => round($this->recall($ranks), 4),
                    'mrr_at_10'  => round($this->mrr($ranks), 4),
                ];
            }
        }

        return [
            'schema_version' => '1.0',
            'cases_file'     => $casesFile,
            'case_count'     => count($cases),
            'map_snapshot'   => $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest,
            'degraded'       => $this->embeddings === null,
            'degraded_reason' => $this->embeddings === null ? 'no_embedding_provider' : null,
            'categories'     => $categories,
            'cases'          => $details,
        ];
    }

    /**
     * A case in the "irrelevant" category expects nothing: finding no match is the correct answer,
     * so it scores as a hit when every channel stays empty.
     *
     * @param list<string> $hits
     * @param list<string> $expected
     */
    private function rankOf(array $hits, array $expected): ?int
    {
        if ($expected === []) {
            return $hits === [] ? 1 : null;
        }

        foreach ($hits as $position => $symbolId) {
            if (in_array($symbolId, $expected, true)) {
                return $position + 1;
            }
        }

        return null;
    }

    /** @param list<int|null> $ranks */
    private function recall(array $ranks): float
    {
        $hit = 0;
        foreach ($ranks as $rank) {
            if ($rank !== null && $rank <= self::RECALL_AT) {
                ++$hit;
            }
        }

        return $ranks === [] ? 0.0 : $hit / count($ranks);
    }

    /** @param list<int|null> $ranks */
    private function mrr(array $ranks): float
    {
        $sum = 0.0;
        foreach ($ranks as $rank) {
            if ($rank !== null && $rank <= self::MRR_AT) {
                $sum += 1 / $rank;
            }
        }

        return $ranks === [] ? 0.0 : $sum / count($ranks);
    }

    /** @return list<string> */
    private function structuralHits(AgentMapIndex $index, string $query): array
    {
        $terms = (new QueryPlanner())->plan($query)['structural_terms'];
        $hits = [];
        foreach ($terms as $term) {
            foreach ($index->query($term)->files as $file) {
                foreach ($file->symbols as $symbol) {
                    $hits[$symbol->id()] = true;
                    foreach ($symbol->methods as $method) {
                        $hits[$symbol->methodId($method)] = true;
                    }
                }
            }
        }

        return array_slice(array_keys($hits), 0, self::MRR_AT);
    }

    /** @return list<string> */
    private function lexicalHits(SearchIndexStore $store, string $query): array
    {
        $hits = [];
        foreach ($store->searchLexical($query, self::MRR_AT) as $row) {
            $hits[] = $row['symbol_id'];
        }

        return $hits;
    }

    /** @return list<string> */
    private function semanticHits(SearchIndexStore $store, string $query): array
    {
        if ($this->embeddings === null || $store->vectorCount() === 0) {
            return [];
        }

        $hits = [];
        foreach ($store->searchSemantic($this->embeddings->embedQuery($query), $this->embeddings->model(), self::MRR_AT) as $row) {
            if ($row['distance'] > HybridSearch::SEMANTIC_MAX_DISTANCE) {
                continue;
            }

            $hits[] = $row['symbol_id'];
        }

        return $hits;
    }

    /** @return list<string> */
    private function hybridHits(AgentMapIndex $index, SearchIndexStore $store, string $query): array
    {
        $hits = [];
        /** @var list<array<string, mixed>> $results */
        $results = $this->hybrid->search($index, $store, $query, self::MRR_AT)['results'];
        foreach ($results as $result) {
            $hits[] = (string)$result['symbol_id'];
        }

        return $hits;
    }

    /**
     * @return list<array{id: string, category: string, query: string, expected: list<string>}>
     */
    private function load(string $casesFile): array
    {
        if (!is_file($casesFile)) {
            throw new RuntimeException('Benchmark cases not found: ' . $casesFile);
        }

        try {
            $decoded = json_decode((string)file_get_contents($casesFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Benchmark cases are malformed: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded) || !is_array($decoded['cases'] ?? null)) {
            throw new RuntimeException('Benchmark cases file must hold a "cases" list: ' . $casesFile);
        }

        $cases = [];
        foreach ($decoded['cases'] as $case) {
            if (!is_array($case) || !is_string($case['id'] ?? null) || !is_string($case['query'] ?? null)) {
                continue;
            }

            $expected = [];
            foreach (is_array($case['expected'] ?? null) ? $case['expected'] : [] as $symbolId) {
                if (is_string($symbolId) && $symbolId !== '') {
                    $expected[] = $symbolId;
                }
            }

            $cases[] = [
                'id'       => $case['id'],
                'category' => is_string($case['category'] ?? null) ? $case['category'] : 'uncategorized',
                'query'    => $case['query'],
                'expected' => $expected,
            ];
        }

        return $cases;
    }
}
