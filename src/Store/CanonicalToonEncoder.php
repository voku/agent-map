<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

use HelgeSverre\Toon\Toon;

final readonly class CanonicalToonEncoder
{
    public function __construct(private CanonicalArrayNormalizer $normalizer = new CanonicalArrayNormalizer())
    {
    }

    /** @param array<string, mixed> $payload */
    public function encode(array $payload): string
    {
        return Toon::encode($this->normalizer->normalize($payload)) . "\n";
    }
}
