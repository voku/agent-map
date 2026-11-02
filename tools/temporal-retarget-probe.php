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
     *   candidates: list<array{source_id: string, kind: string, resolution: string, receiver_type: ?string, result_type: ?string, from_target_ids: string, to_target_ids: string, from_count: int, to_count: int, assessment: 'retarget_candidate_unverified'}>,
     *   ambiguous_groups: list<array{source_id: string, kind: string, removed: int, added: int, reason: 'multiple_possible_pairings'}>,
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

        /** @var array<string, array{source_id: string, kind: string, resolution: string, receiver_type: ?string, result_type: ?string, removed: list<array{target_ids: string, count: int}>, added: list<array{target_ids: string, count: int}>}> $groups */
        $groups = [];
        foreach ($report['events'] as $event) {
            if (!is_array($event)) {
                throw new RuntimeException('Unexpected non-object history event.');
            }
            $eventKind = $event['kind'] ?? null;
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
        $ambiguous = [];
        foreach ($groups as $group) {
            if ($group['removed'] === [] || $group['added'] === []) {
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

        return [
            'schema_version' => 1,
            'source_type' => 'agent-map.history_diff',
            'evidence_level' => 'UNVERIFIED',
            'before_map_digest' => $report['before_map_digest'],
            'after_map_digest' => $report['after_map_digest'],
            'candidate_count' => count($candidates),
            'ambiguous_group_count' => count($ambiguous),
            'candidates' => $candidates,
            'ambiguous_groups' => $ambiguous,
            'limitations' => [
                'A candidate is not verified call-site identity; independent changes may look like a retarget.',
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
    $bad = testDiffReport($events);
    $bad['truncated'] = true;
    try {
        $probe->analyze($bad);
    } catch (RuntimeException) {
        echo "[PASS] semantic retarget probe: deterministic, ambiguous-safe, grouped-targets, truncated-fail-closed.\n";
        return;
    }
    throw new RuntimeException('Truncated report was accepted.');
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
