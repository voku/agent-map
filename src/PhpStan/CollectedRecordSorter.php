<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan;

/**
 * Order the collector output with exactly the historical comparison keys.
 *
 * array_multisort compares the precomputed keys in PHP's native sort engine,
 * rather than invoking a PHP comparator for each comparison. An original
 * ordinal is the final key so equal records remain in original order.
 */
final readonly class CollectedRecordSorter
{
    /**
     * Three-runtime CI measurements found ~16 MiB extra peak at 50k records,
     * ~59 MiB at 180k with the native six-key arrays. Keep an approximate
     * 20–25 MiB temporary-key budget and prefer lower peak RAM beyond it.
     */
    private const MAX_NATIVE_RECORDS = 65_536;

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    public static function sort(array $records): array
    {
        $count = count($records);
        if ($count < 2) {
            return $records;
        }

        if ($count > self::MAX_NATIVE_RECORDS) {
            // PHPStan also runs against very large, memory-constrained codebases.
            // Avoid six O(n) key arrays if their peak memory becomes material.
            usort($records, static function (array $left, array $right): int {
                return ((string) $left['record_type']) <=> ((string) $right['record_type'])
                    ?: ((string) ($left['file'] ?? '')) <=> ((string) ($right['file'] ?? ''))
                    ?: ((int) ($left['line_start'] ?? 0)) <=> ((int) ($right['line_start'] ?? 0))
                    ?: ((string) ($left['name'] ?? $left['kind'] ?? '')) <=> ((string) ($right['name'] ?? $right['kind'] ?? ''))
                    ?: ((string) ($left['source_id'] ?? '')) <=> ((string) ($right['source_id'] ?? ''));
            });

            return $records;
        }

        $types = [];
        $files = [];
        $lines = [];
        $names = [];
        $sources = [];
        $ordinals = [];

        foreach ($records as $ordinal => $record) {
            $types[] = (string) $record['record_type'];
            $files[] = (string) ($record['file'] ?? '');
            $lines[] = (int) ($record['line_start'] ?? 0);
            $names[] = (string) ($record['name'] ?? $record['kind'] ?? '');
            $sources[] = (string) ($record['source_id'] ?? '');
            $ordinals[] = $ordinal;
        }

        array_multisort(
            $types, SORT_ASC, SORT_REGULAR,
            $files, SORT_ASC, SORT_REGULAR,
            $lines, SORT_ASC, SORT_NUMERIC,
            $names, SORT_ASC, SORT_REGULAR,
            $sources, SORT_ASC, SORT_REGULAR,
            $ordinals, SORT_ASC, SORT_NUMERIC,
            $records,
        );

        return $records;
    }
}
