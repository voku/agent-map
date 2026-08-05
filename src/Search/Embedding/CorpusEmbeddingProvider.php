<?php

declare(strict_types=1);

namespace voku\AgentMap\Search\Embedding;

/**
 * Embeddings derived from the repository's own vocabulary - no model download, no network, no FFI.
 *
 * Terms are hashed into a fixed number of buckets and weighted by inverse document frequency, so
 * two chunks that share rare vocabulary end up close even when they share no identifier. That is
 * genuinely weaker than a trained sentence encoder: it captures co-occurrence, not meaning, and it
 * cannot know that "retry" and "attempt" are related unless they appear together somewhere in this
 * repository.
 *
 * It exists because it is honest about being local and dependency-free, and because it gives the
 * vector path something real to index while a trained provider is a deliberate, separate decision.
 * `CommandEmbeddingProvider` is the seam for that better model.
 */
final class CorpusEmbeddingProvider implements EmbeddingProvider
{
    private const DIMENSIONS = 256;

    /** @var array<string, float> */
    private array $inverseDocumentFrequency = [];

    /**
     * token => [bucket, sign]. Hashing is the whole cost of this provider: a repository re-uses the
     * same few thousand identifiers across tens of thousands of chunks, so hashing each occurrence
     * again is the difference between seconds and minutes.
     *
     * @var array<string, array{0: int, 1: float}>
     */
    private array $placement = [];

    private string $corpusRevision = 'empty';

    /**
     * @return array{revision: string, weights: array<string, float>}
     */
    public function state(): array
    {
        return ['revision' => $this->corpusRevision, 'weights' => $this->inverseDocumentFrequency];
    }

    /**
     * Restores a previously fitted weighting.
     *
     * A refresh must not refit: new vocabulary would shift every weight, change the model
     * fingerprint and force all 20 000 chunks to be embedded again for the sake of one edited file.
     * The weighting is therefore part of the index, and refitting is a full-build decision.
     *
     * @param array{revision: string, weights: array<string, float>} $state
     */
    public function restore(array $state): void
    {
        $this->corpusRevision = $state['revision'];
        $this->inverseDocumentFrequency = $state['weights'];
    }

    /**
     * Learns the vocabulary weighting from the corpus it will embed. Doing this per repository is
     * the point: rare terms here are what carry meaning here.
     *
     * @param list<string> $documents
     */
    public function fit(array $documents): void
    {
        $documentCount = max(1, count($documents));
        $seenIn = [];
        foreach ($documents as $document) {
            foreach (array_unique($this->tokenize($document)) as $token) {
                $seenIn[$token] = ($seenIn[$token] ?? 0) + 1;
            }
        }

        $this->inverseDocumentFrequency = [];
        foreach ($seenIn as $token => $count) {
            $this->inverseDocumentFrequency[$token] = log(($documentCount + 1) / ($count + 1)) + 1.0;
        }

        ksort($this->inverseDocumentFrequency);
        $this->corpusRevision = substr(hash('sha256', (string)json_encode(array_keys($this->inverseDocumentFrequency))), 0, 16);
    }

    public function model(): EmbeddingModel
    {
        return new EmbeddingModel(
            provider: 'corpus',
            model: 'hashed-idf',
            revision: $this->corpusRevision,
            dimensions: self::DIMENSIONS,
        );
    }

    public function embedDocuments(array $documents): array
    {
        $vectors = [];
        foreach ($documents as $document) {
            $vectors[] = $this->embed($document);
        }

        return $vectors;
    }

    public function embedQuery(string $query): array
    {
        return $this->embed($query);
    }

    /** @return list<float> */
    private function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);
        foreach ($this->tokenize($text) as $token) {
            // An unseen token still contributes: a query word that appears nowhere in the corpus is
            // rare by definition, and dropping it would make such queries embed as noise.
            $weight = $this->inverseDocumentFrequency[$token] ?? 1.0;
            $placement = $this->placement[$token] ?? null;
            if ($placement === null) {
                $digest = hash('sha256', $token);
                $placement = [
                    (int)(hexdec(substr($digest, 0, 8)) % self::DIMENSIONS),
                    hexdec(substr($digest, 8, 2)) % 2 === 0 ? 1.0 : -1.0,
                ];
                $this->placement[$token] = $placement;
            }

            $vector[$placement[0]] += $placement[1] * $weight;
        }

        return EmbeddingVector::normalize(array_values($vector));
    }

    /**
     * Identifiers are split into their parts as well as kept whole: `assertComputerNamesCanBeRequested`
     * has to be reachable from the words "computer" and "request".
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        // One split instead of two: the whole-token and part-token passes shared the same regex work.
        $tokens = [];
        // Split the original casing first: lowercasing up front would erase the CamelCase boundary
        // this relies on to reach `assertComputerNames...` from the word "computer".
        foreach (preg_split('/[^\p{L}\p{N}_]+/u', $text) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw === '' || mb_strlen($raw) < 3) {
                continue;
            }

            $whole = mb_strtolower($raw);
            $tokens[] = $whole;
            foreach (preg_split('/_+|(?<=[a-z0-9])(?=[A-Z])/', $raw) ?: [] as $part) {
                $part = mb_strtolower(trim($part));
                if (mb_strlen($part) >= 3 && $part !== $whole) {
                    $tokens[] = $part;
                }
            }
        }

        return $tokens;
    }
}
