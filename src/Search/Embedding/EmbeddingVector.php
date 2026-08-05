<?php

declare(strict_types=1);

namespace voku\AgentMap\Search\Embedding;

use RuntimeException;

/**
 * Encodes and validates vectors on the boundary to storage.
 *
 * sqlite-vec reads little-endian float32 blobs. Encoding is therefore explicit rather than
 * whatever pack() happens to do on this machine, and every vector is checked for dimension and
 * finiteness first: a NaN reaching the index makes every later distance meaningless and there is no
 * way to notice afterwards.
 */
final readonly class EmbeddingVector
{
    /** @param list<float> $values */
    public static function encode(array $values, int $expectedDimensions): string
    {
        if (count($values) !== $expectedDimensions) {
            throw new RuntimeException(sprintf('Embedding has %d dimensions, expected %d.', count($values), $expectedDimensions));
        }

        $blob = '';
        foreach ($values as $value) {
            if (!is_finite($value)) {
                throw new RuntimeException('Embedding contains a non-finite value.');
            }
            $blob .= pack('g', $value);
        }

        return $blob;
    }

    /**
     * @param list<float> $values
     *
     * @return list<float>
     */
    public static function normalize(array $values): array
    {
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += $value * $value;
        }
        if ($sum <= 0.0) {
            return $values;
        }

        $length = sqrt($sum);
        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = $value / $length;
        }

        return $normalized;
    }
}
