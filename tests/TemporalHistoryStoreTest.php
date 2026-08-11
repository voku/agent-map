<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Temporal\EntityHistoryReport;
use voku\AgentMap\Temporal\EntityObservation;
use voku\AgentMap\Temporal\GitRevision;
use voku\AgentMap\Temporal\TemporalHistoryStore;

final class TemporalHistoryStoreTest extends TestCase
{
    public function testEntityAbsenceFromLatestSnapshotIsDerivedWithoutStoringRemovalEvents(): void
    {
        $database = sys_get_temp_dir() . '/agent-map-history-' . bin2hex(random_bytes(6)) . '.sqlite';
        $store = new TemporalHistoryStore($database);
        $entity = 'method:App\\Foo::run';

        try {
            $store->record(
                $this->map('before'),
                new GitRevision(str_repeat('a', 40), '2026-08-10T10:00:00+00:00'),
                [$this->observation($entity, callers: 2, dependencies: 1)],
            );
            $store->record(
                $this->map('after'),
                new GitRevision(str_repeat('b', 40), '2026-08-11T10:00:00+00:00'),
                [],
            );

            $report = new EntityHistoryReport($entity, $store->entityHistory($entity), $store->latestSnapshot());

            self::assertSame(2, $store->snapshotCount());
            self::assertSame(1, $store->observationCount());
            self::assertSame('absent_from_latest', $report->status());
            self::assertCount(1, $report->observations);
        } finally {
            @unlink($database);
            @unlink($database . '-shm');
            @unlink($database . '-wal');
        }
    }

    public function testHistoryExposesSignatureRegionAndGraphMetricEvolution(): void
    {
        $database = sys_get_temp_dir() . '/agent-map-history-' . bin2hex(random_bytes(6)) . '.sqlite';
        $store = new TemporalHistoryStore($database);
        $entity = 'method:App\\Foo::run';

        try {
            $store->record(
                $this->map('one'),
                new GitRevision(str_repeat('1', 40), '2026-08-09T10:00:00+00:00'),
                [$this->observation($entity, callers: 1, dependencies: 2, signature: 'public run(): int', region: 'Domain')],
            );
            $store->record(
                $this->map('two'),
                new GitRevision(str_repeat('2', 40), '2026-08-10T10:00:00+00:00'),
                [$this->observation($entity, callers: 4, dependencies: 5, signature: 'public run(): string', region: 'Application')],
            );
            $store->record(
                $this->map('three'),
                new GitRevision(str_repeat('3', 40), '2026-08-11T10:00:00+00:00'),
                [$this->observation($entity, callers: 7, dependencies: 6, signature: 'public run(): string', region: 'Application')],
            );

            $report = new EntityHistoryReport($entity, $store->entityHistory($entity), $store->latestSnapshot());
            $payload = $report->toArray();

            self::assertSame('present', $report->status());
            self::assertSame(3, $payload['observation_count']);
            self::assertSame(2, $payload['signature_variants']);
            self::assertSame(2, $payload['region_variants']);
            self::assertSame(6, $payload['metric_delta']['callers']);
            self::assertSame(4, $payload['metric_delta']['dependencies']);
        } finally {
            @unlink($database);
            @unlink($database . '-shm');
            @unlink($database . '-wal');
        }
    }

    private function map(string $salt): AgentMapIndex
    {
        return new AgentMapIndex('2.0', '/tmp/agent-map-history', 'test-' . $salt, []);
    }

    private function observation(
        string $entity,
        int $callers = 0,
        int $dependencies = 0,
        string $signature = 'public run(): void',
        string $region = 'Domain',
    ): EntityObservation {
        return new EntityObservation(
            'method',
            $entity,
            'src/Foo.php',
            $signature,
            $callers,
            0,
            $callers,
            $dependencies,
            'region-' . strtolower($region),
            $region,
        );
    }
}
