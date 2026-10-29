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
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    public static function sort(array $records): array
    {
        if (count($records) < 2) {
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
