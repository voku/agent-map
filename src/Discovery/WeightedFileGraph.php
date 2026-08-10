<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class WeightedFileGraph
{
    /**
     * @param list<string> $files
     * @param array<string, array<string, float>> $adjacency
     * @param array<string, array<string, float>> $signalsByPair
     */
    public function __construct(
        public array $files,
        public array $adjacency,
        private array $signalsByPair,
    ) {
    }

    /** @return array<string, float> */
    public function neighbours(string $file): array
    {
        return $this->adjacency[$file] ?? [];
    }

    public function degree(string $file): float
    {
        return array_sum($this->neighbours($file));
    }

    public function totalWeight(): float
    {
        $weight = 0.0;
        foreach ($this->adjacency as $neighbours) {
            $weight += array_sum($neighbours);
        }

        return $weight / 2.0;
    }

    public function edgeWeight(string $left, string $right): float
    {
        return $this->adjacency[$left][$right] ?? 0.0;
    }

    /** @return array<string, float> */
    public function signalsBetween(string $left, string $right): array
    {
        return $this->signalsByPair[self::pairKey($left, $right)] ?? [];
    }

    public static function pairKey(string $left, string $right): string
    {
        return $left < $right ? $left . "\0" . $right : $right . "\0" . $left;
    }
}
