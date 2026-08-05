<?php

declare(strict_types=1);

namespace voku\AgentMap\Search\Embedding;

/**
 * Identity of the vector space an embedding belongs to.
 *
 * The fingerprint covers everything that changes the geometry: provider, model, revision,
 * dimensions and normalization. Two vectors with different fingerprints are not comparable, and the
 * index refuses to mix them rather than returning nearest neighbours from two different spaces.
 */
final readonly class EmbeddingModel
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $revision,
        public int $dimensions,
        public string $normalization = 'l2',
    ) {
    }

    public function fingerprint(): string
    {
        return 'sha256:' . hash('sha256', implode("\0", [
            $this->provider,
            $this->model,
            $this->revision,
            (string)$this->dimensions,
            $this->normalization,
        ]));
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'provider'      => $this->provider,
            'model'         => $this->model,
            'revision'      => $this->revision,
            'dimensions'    => $this->dimensions,
            'normalization' => $this->normalization,
            'fingerprint'   => $this->fingerprint(),
        ];
    }
}
