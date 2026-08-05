<?php

declare(strict_types=1);

namespace voku\AgentMap\Search\Embedding;

/**
 * Turns text into vectors. Everything about *which* vectors is the model's business, not the
 * index's - which is why the model fingerprint travels with every embedding and a mismatch forces a
 * rebuild instead of silently mixing two vector spaces in one table.
 */
interface EmbeddingProvider
{
    public function model(): EmbeddingModel;

    /**
     * @param list<string> $documents
     *
     * @return list<list<float>> one vector per document, in input order
     */
    public function embedDocuments(array $documents): array;

    /** @return list<float> */
    public function embedQuery(string $query): array;
}
