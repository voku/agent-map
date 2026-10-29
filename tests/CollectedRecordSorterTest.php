<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\PhpStan\CollectedRecordSorter;

final class CollectedRecordSorterTest extends TestCase
{
    public function testEmptySingleAndStableEqualKeys(): void
    {
        self::assertSame([], CollectedRecordSorter::sort([]));
        $one = ['record_type' => 'type', 'file' => 'test.php', 'line_start' => 1];
        self::assertSame([$one], CollectedRecordSorter::sort([$one]));

        $equal = [];
        for ($i = 0; $i < 200; ++$i) {
            $equal[] = ['record_type' => 'type', 'file' => 'test.php', 'line_start' => 1, 'name' => 'm', 'source_id' => 's', 'payload' => $i];
        }
        self::assertSame($equal, CollectedRecordSorter::sort($equal));
    }

    public function testMemoryBoundedSortKeepsExactOrderAboveThreshold(): void
    {
        // 65,536 uses the native fast path; 65,537 must use the low-memory path.
        // Ensure the actual returned records remain byte-identical to the
        // historical comparator across both sides of the threshold.
        foreach ([65_536, 65_537] as $count) {
            $records = [];
            for ($i = 0; $i < $count; ++$i) {
                $records[] = [
                    'record_type' => (string) ($i % 13),
                    'file' => 'src/File_' . ($i % 37) . '.php',
                    'line_start' => $i % 29,
                    'name' => $i % 2 === 0 ? (string) ($i % 19) : 'name_' . ($i % 19),
                    'source_id' => (string) ($i % 17),
                    'ordinal' => $i,
                ];
            }

            $expected = $records;
            usort($expected, static function (array $left, array $right): int {
                return ((string) $left['record_type']) <=> ((string) $right['record_type'])
                    ?: ((string) ($left['file'] ?? '')) <=> ((string) ($right['file'] ?? ''))
                    ?: ((int) ($left['line_start'] ?? 0)) <=> ((int) ($right['line_start'] ?? 0))
                    ?: ((string) ($left['name'] ?? $left['kind'] ?? '')) <=> ((string) ($right['name'] ?? $right['kind'] ?? ''))
                    ?: ((string) ($left['source_id'] ?? '')) <=> ((string) ($right['source_id'] ?? ''));
            });

            self::assertSame($expected, CollectedRecordSorter::sort($records), 'record count ' . $count);
        }
    }

    public function testNativeSortIsByteIdenticalToOriginalComparatorAcrossMixedKeys(): void
    {
        $records = [];
        $files = ['src/A.php', '', 'src/B.php', '2', '10', '01', '0'];
        $names = ['a', '', '2', '10', '02', 'X', 'm'];
        $types = ['class', 'method', 'relation', 'type', '1', '02'];
        for ($i = 0; $i < 5000; ++$i) {
            $record = [
                'record_type' => $types[($i * 17 + 2) % count($types)],
                'file' => $files[($i * 23 + 3) % count($files)],
                'line_start' => ($i * 31) % 7 - 3,
                'name' => $names[($i * 7 + 4) % count($names)],
                'source_id' => $names[($i * 13 + 1) % count($names)],
                'payload' => $i,
            ];
            if ($i % 13 === 0) {
                unset($record['name']);
                $record['kind'] = 'fallback';
            }
            if ($i % 11 === 0) {
                unset($record['line_start']);
            }
            if ($i % 19 === 0) {
                unset($record['source_id']);
            }
            $records[] = $record;
        }

        $expected = $records;
        usort($expected, static function (array $left, array $right): int {
            return ((string) $left['record_type']) <=> ((string) $right['record_type'])
                ?: ((string) ($left['file'] ?? '')) <=> ((string) ($right['file'] ?? ''))
                ?: ((int) ($left['line_start'] ?? 0)) <=> ((int) ($right['line_start'] ?? 0))
                ?: ((string) ($left['name'] ?? $left['kind'] ?? '')) <=> ((string) ($right['name'] ?? $right['kind'] ?? ''))
                ?: ((string) ($left['source_id'] ?? '')) <=> ((string) ($right['source_id'] ?? ''));
        });

        self::assertSame($expected, CollectedRecordSorter::sort($records));
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode(CollectedRecordSorter::sort($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
