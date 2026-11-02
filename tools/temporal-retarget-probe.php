<?php

declare(strict_types=1);

/**
 * Read-only experiment over the existing agent-map history diff --format=json contract.
 * A candidate is NEVER evidence of call-site identity or mutation authority.
 */
final readonly class TemporalRetargetProbe
{
    /**
     * @param array<string, mixed> $report
     * @return array{
     *   schema_version: 1,
     *   source_type: 'agent-map.history_diff',
     *   evidence_level: 'UNVERIFIED',
     *   before_map_digest: string,
     *   after_map_digest: string,
     *   candidate_count: int,
     *   ambiguous_group_count: int,
     *   suppressed_non_call_group_count: int,
     *   possible_rename_count: int,
     *   repeated_migration_group_count: int,
     *   candidates: list<array{source_id: string, kind: string, resolution: string, receiver_type: ?string, result_type: ?string, from_target_ids: string, to_target_ids: string, from_count: int, to_count: int, assessment: 'retarget_candidate_unverified'}>,
     *   ambiguous_groups: list<array{source_id: string, kind: string, removed: int, added: int, reason: 'multiple_possible_pairings'}>,
     *   possible_renames: list<array{source_id: string, from_target_ids: string, to_target_ids: string, evidence: 'method_removed_and_added', assessment: 'possible_rename_unverified'}>,
     *   repeated_migrations: list<array{from_target_ids: string, to_target_ids: string, resolution: string, receiver_type: ?string, result_type: ?string, source_ids: non-empty-list<string>, occurrence_count: int, assessment: 'repeated_call_target_migration_unverified'}>,
     *   limitations: list<string>
     * }
     */
    public function analyze(array $report): array
    {
        if (($report['type'] ?? null) !== 'history_diff'
            || !is_string($report['before_map_digest'] ?? null)
            || !is_string($report['after_map_digest'] ?? null)
            || !is_array($report['events'] ?? null)
            || !is_int($report['event_count'] ?? null)
            || ($report['truncated'] ?? null) !== false
            || count($report['events']) !== $report['event_count']
        ) {
            throw new RuntimeException('Expected a complete, non-truncated agent-map history_diff report.');
        }

        // Method lifecycle is evidence for a *possible* rename, not proof that a
        // removed call was retargeted to an unrelated implementation.
        /** @var array<string, true> $removedMethods */
        $removedMethods = [];
        /** @var array<string, true> $addedMethods */
        $addedMethods = [];
        /** @var array<string, array{source_id: string, kind: string, resolution: string, receiver_type: ?string, result_type: ?string, removed: list<array{target_ids: string, count: int}>, added: list<array{target_ids: string, count: int}>}> $groups */
        $groups = [];
        foreach ($report['events'] as $event) {
            if (!is_array($event)) {
                throw new RuntimeException('Unexpected non-object history event.');
            }
            $eventKind = $event['kind'] ?? null;
            if ($eventKind === 'method_removed' || $eventKind === 'method_added') {
                if (!is_string($event['entity_id'] ?? null) || !str_starts_with($event['entity_id'], 'method:')) {
                    throw new RuntimeException('Malformed method lifecycle event in history diff.');
                }
                if ($eventKind === 'method_removed') {
                    $removedMethods[$event['entity_id']] = true;
                } else {
                    $addedMethods[$event['entity_id']] = true;
                }
                continue;
            }
            if (!in_array($eventKind, ['relation_removed', 'relation_added'], true)) {
                continue;
            }
            $direction = $eventKind === 'relation_removed' ? 'removed' : 'added';
            $payload = $eventKind === 'relation_removed' ? ($event['before'] ?? null) : ($event['after'] ?? null);
            if (!is_array($payload)
                || !is_string($payload['source_id'] ?? null)
                || !is_string($payload['kind'] ?? null)
                || !is_string($payload['target_ids'] ?? null)
                || !is_string($payload['resolution'] ?? null)
                || !array_key_exists('receiver_type', $payload)
                || !array_key_exists('result_type', $payload)
                || (!is_string($payload['receiver_type']) && $payload['receiver_type'] !== null)
                || (!is_string($payload['result_type']) && $payload['result_type'] !== null)
                || !is_int($payload['count'] ?? null)
                || $payload['count'] < 1
            ) {
                throw new RuntimeException('Malformed relation event in history diff.');
            }
            $context = [
                'source_id' => $payload['source_id'],
                'kind' => $payload['kind'],
                'resolution' => $payload['resolution'],
                'receiver_type' => $payload['receiver_type'],
                'result_type' => $payload['result_type'],
            ];
            $key = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!isset($groups[$key])) {
                $groups[$key] = [...$context, 'removed' => [], 'added' => []];
            }
            $groups[$key][$direction][] = ['target_ids' => $payload['target_ids'], 'count' => $payload['count']];
        }
        ksort($groups, SORT_STRING);
        $candidates = [];
        $possibleRenames = [];
        $ambiguous = [];
        $suppressedNonCallGroups = 0;
        foreach ($groups as $group) {
            if ($group['removed'] === [] || $group['added'] === []) {
                continue;
            }
            // Declares-property / declares-method / inheritance set differences
            // are not call retargets, even if they share one stable source ID.
            if ($group['kind'] !== 'calls' || !str_starts_with($group['source_id'], 'method:')) {
                ++$suppressedNonCallGroups;
                continue;
            }
            if (count($group['removed']) !== 1 || count($group['added']) !== 1) {
                $ambiguous[] = [
                    'source_id' => $group['source_id'],
                    'kind' => $group['kind'],
                    'removed' => count($group['removed']),
                    'added' => count($group['added']),
                    'reason' => 'multiple_possible_pairings',
                ];
                continue;
            }
            $old = $group['removed'][0];
            $new = $group['added'][0];
            if ($old['target_ids'] === $new['target_ids']) {
                continue;
            }
            // Only single-target method lifecycle can support even a possible
            // rename. Multi-target groups stay opaque; no edge flattening.
            if (!str_contains($old['target_ids'], ',')
                && !str_contains($new['target_ids'], ',')
                && isset($removedMethods[$old['target_ids']], $addedMethods[$new['target_ids']])
            ) {
                $possibleRenames[] = [
                    'source_id' => $group['source_id'],
                    'from_target_ids' => $old['target_ids'],
                    'to_target_ids' => $new['target_ids'],
                    'evidence' => 'method_removed_and_added',
                    'assessment' => 'possible_rename_unverified',
                ];
                continue;
            }
            $candidates[] = [
                'source_id' => $group['source_id'],
                'kind' => $group['kind'],
                'resolution' => $group['resolution'],
                'receiver_type' => $group['receiver_type'],
                'result_type' => $group['result_type'],
                'from_target_ids' => $old['target_ids'],
                'to_target_ids' => $new['target_ids'],
                'from_count' => $old['count'],
                'to_count' => $new['count'],
                'assessment' => 'retarget_candidate_unverified',
            ];
        }

        // Aggregate a repeated migration without conflating distinct relation
        // contexts. This grouping is review evidence, never proof of intent.
        /** @var array<string, array{from_target_ids: string, to_target_ids: string, resolution: string, receiver_type: ?string, result_type: ?string, source_ids: list<string>}> $migrations */
        $migrations = [];
        foreach ($candidates as $candidate) {
            $data = [
                'from_target_ids' => $candidate['from_target_ids'],
                'to_target_ids' => $candidate['to_target_ids'],
                'resolution' => $candidate['resolution'],
                'receiver_type' => $candidate['receiver_type'],
                'result_type' => $candidate['result_type'],
            ];
            $key = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $migrations[$key] ??= [...$data, 'source_ids' => []];
            $migrations[$key]['source_ids'][] = $candidate['source_id'];
        }
        ksort($migrations, SORT_STRING);
        $repeatedMigrations = [];
        foreach ($migrations as $migration) {
            if (count($migration['source_ids']) < 2) {
                continue;
            }
            sort($migration['source_ids'], SORT_STRING);
            $repeatedMigrations[] = [
                ...$migration,
                'occurrence_count' => count($migration['source_ids']),
                'assessment' => 'repeated_call_target_migration_unverified',
            ];
        }

        return [
            'schema_version' => 2,
            'source_type' => 'agent-map.history_diff',
            'evidence_level' => 'UNVERIFIED',
            'before_map_digest' => $report['before_map_digest'],
            'after_map_digest' => $report['after_map_digest'],
            'candidate_count' => count($candidates),
            'ambiguous_group_count' => count($ambiguous),
            'suppressed_non_call_group_count' => $suppressedNonCallGroups,
            'possible_rename_count' => count($possibleRenames),
            'repeated_migration_group_count' => count($repeatedMigrations),
            'candidates' => $candidates,
            'ambiguous_groups' => $ambiguous,
            'possible_renames' => $possibleRenames,
            'repeated_migrations' => $repeatedMigrations,
            'limitations' => [
                'A candidate is not verified call-site identity; independent changes may look like a retarget.',
                'A possible rename requires removed/added method IDs but is NOT a verified rename.',
                'Only calls are classified as retargets; declarations and set differences are excluded.',
                'Multi-target relations are compared as opaque target_ids strings, not flattened edges.',
                'No node rename, split, merge, authorization, or lifecycle conclusion is made.',
            ],
        ];
    }
}

/** @return array<string, mixed> */
function testDiffReport(array $events): array
{
    return [
        'type' => 'history_diff',
        'before_map_digest' => 'before-test-digest',
        'after_map_digest' => 'after-test-digest',
        'event_count' => count($events),
        'events' => $events,
        'truncated' => false,
    ];
}

/** @return array<string, mixed> */
function testRelationEvent(string $direction, string $source, string $targets): array
{
    $data = [
        'source_id' => $source,
        'kind' => 'calls',
        'target_ids' => $targets,
        'resolution' => 'static',
        'receiver_type' => null,
        'result_type' => null,
        'count' => 1,
    ];
    return [
        'kind' => 'relation_' . $direction,
        'before' => $direction === 'removed' ? $data : [],
        'after' => $direction === 'added' ? $data : [],
    ];
}

function testProbe(): void
{
    $probe = new TemporalRetargetProbe();
    $events = [
        testRelationEvent('removed', 'method:Example::run', 'method:Legacy::run'),
        testRelationEvent('added', 'method:Example::run', 'method:Modern::run'),
    ];
    $first = $probe->analyze(testDiffReport($events));
    $reversed = $probe->analyze(testDiffReport(array_reverse($events)));
    if ($first !== $reversed || $first['candidate_count'] !== 1 || $first['ambiguous_group_count'] !== 0
        || $first['candidates'][0]['assessment'] !== 'retarget_candidate_unverified'
    ) {
        throw new RuntimeException('Unambiguous candidate or order-independence proof failed.');
    }
    $split = $probe->analyze(testDiffReport([
        ...$events,
        testRelationEvent('added', 'method:Example::run', 'method:Third::run'),
    ]));
    if ($split['candidate_count'] !== 0 || $split['ambiguous_group_count'] !== 1) {
        throw new RuntimeException('Ambiguous candidate was silently paired.');
    }
    $multiTarget = $probe->analyze(testDiffReport([
        testRelationEvent('removed', 'method:Example::run', 'method:A,method:B'),
        testRelationEvent('added', 'method:Example::run', 'method:A,method:C'),
    ]));
    if ($multiTarget['candidate_count'] !== 1
        || $multiTarget['candidates'][0]['from_target_ids'] !== 'method:A,method:B'
    ) {
        throw new RuntimeException('Multi-target group was not preserved.');
    }
    $propertyEvent = static function (string $direction, string $targets): array {
        $event = testRelationEvent($direction, 'type:App\\Plan', $targets);
        $field = $direction === 'removed' ? 'before' : 'after';
        $event[$field]['kind'] = 'declares_property';
        return $event;
    };
    $nonCall = $probe->analyze(testDiffReport([
        $propertyEvent('removed', 'property:App\\Plan::$one'),
        $propertyEvent('added', 'property:App\\Plan::$one,property:App\\Plan::$two'),
    ]));
    if ($nonCall['candidate_count'] !== 0 || $nonCall['suppressed_non_call_group_count'] !== 1) {
        throw new RuntimeException('Declaration-set change was misclassified as retarget.');
    }

    $oldMethod = 'method:Example::oldName';
    $newMethod = 'method:Example::newName';
    $rename = $probe->analyze(testDiffReport([
        testRelationEvent('removed', 'method:Caller::run', $oldMethod),
        testRelationEvent('added', 'method:Caller::run', $newMethod),
        ['kind' => 'method_removed', 'entity_id' => $oldMethod],
        ['kind' => 'method_added', 'entity_id' => $newMethod],
    ]));
    if ($rename['candidate_count'] !== 0 || $rename['possible_rename_count'] !== 1) {
        throw new RuntimeException('Method lifecycle rename evidence was ignored.');
    }

    $migrationEvents = [
        ...$events,
        testRelationEvent('removed', 'method:Second::run', 'method:Legacy::run'),
        testRelationEvent('added', 'method:Second::run', 'method:Modern::run'),
    ];
    $migration = $probe->analyze(testDiffReport($migrationEvents));
    if ($migration['candidate_count'] !== 2 || $migration['repeated_migration_group_count'] !== 1
        || $migration['repeated_migrations'][0]['occurrence_count'] !== 2
        || $migration !== $probe->analyze(testDiffReport(array_reverse($migrationEvents)))
    ) {
        throw new RuntimeException('Repeated migration grouping or determinism failed.');
    }

    // Isolate every rejection path and assert its exact failure reason:
    // catching any RuntimeException can silently hide unrelated test failures.
    $valid = testDiffReport($events);
    $missingTruncated = $valid;
    unset($missingTruncated['truncated']);
    $rejected = [
        'truncated' => [...$valid, 'truncated' => true],
        'event_count mismatch' => [...$valid, 'event_count' => $valid['event_count'] + 1],
        'missing truncated flag' => $missingTruncated,
        'malformed relation' => testDiffReport([
            ['kind' => 'relation_removed', 'before' => ['source_id' => 'method:A::b']],
        ]),
        'malformed method lifecycle' => testDiffReport([
            ['kind' => 'method_added', 'entity_id' => 'type:NotAMethod'],
        ]),
    ];
    $expectedMessages = [
        'truncated' => 'Expected a complete, non-truncated agent-map history_diff report.',
        'event_count mismatch' => 'Expected a complete, non-truncated agent-map history_diff report.',
        'missing truncated flag' => 'Expected a complete, non-truncated agent-map history_diff report.',
        'malformed relation' => 'Malformed relation event in history diff.',
        'malformed method lifecycle' => 'Malformed method lifecycle event in history diff.',
    ];
    foreach ($rejected as $name => $report) {
        try {
            $probe->analyze($report);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === $expectedMessages[$name]) {
                continue;
            }

            throw new RuntimeException('Unexpected validation failure for: ' . $name, 0, $exception);
        }

        throw new RuntimeException('Invalid report was accepted: ' . $name);
    }
    echo "[PASS] semantic retarget probe: call-only, rename-evidence-separated, repeated-migrations, deterministic, ambiguous-safe, grouped-targets, five explicit fail-closed fixtures.\n";
}

try {
    if ($argc === 2 && $argv[1] === '--self-test') {
        testProbe();
        exit(0);
    }
    if ($argc !== 2 || $argv[1] === '--help') {
        fwrite(STDERR, "Usage: php tools/temporal-retarget-probe.php <complete-history-diff.json> | --self-test\n");
        exit(2);
    }
    $content = file_get_contents($argv[1]);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read history diff input.');
    }
    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('History diff input must be a JSON object.');
    }
    echo json_encode((new TemporalRetargetProbe())->analyze($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] semantic retarget probe: ' . $e->getMessage() . "\n");
    exit(1);
}
