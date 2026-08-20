<?php

declare(strict_types=1);

/**
 * Renders the comparable evidence table from the replay reports (agent-map issue #25, M2).
 *
 * Reading cost is reported per strategy in the unit each strategy actually pays:
 * the baseline pays grep output plus opened source, the map strategies pay the projection they add
 * to the prompt plus the exact ranges the model then reads.
 *
 * Usage: php tools/dogfood/summarize-replays.php --reports=/path/to/reports [--markdown]
 */

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2] ?? '1';
    }
}
$directory = $options['reports'] ?? '';
if ($directory === '' || !is_dir($directory)) {
    fwrite(STDERR, "Missing or unreadable --reports directory\n");
    exit(1);
}

$files = glob(rtrim($directory, '/') . '/*.json') ?: [];
sort($files, SORT_STRING);

$rows = [];
foreach ($files as $file) {
    $report = json_decode((string) file_get_contents($file), true);
    if (
        !is_array($report)
        || !is_array($report['replay'] ?? null)
        || !is_array($report['strategies'] ?? null)
        || !is_array($report['observation_envelope'] ?? null)
    ) {
        continue;
    }
    $envelope = $report['observation_envelope'];
    foreach ($report['strategies'] as $strategy) {
        if (!is_array($strategy)) {
            continue;
        }
        $readBytes = $strategy['source_bytes_read_window_model']
            ?? $strategy['source_bytes_read_before_correct_range']
            ?? 0;
        $rows[] = [
            'replay' => $report['replay']['id'] ?? 'unknown',
            'shape' => $report['replay']['shape'] ?? 'unknown',
            'backend' => $envelope['requested_backend'] ?? $envelope['backend'] ?? 'unknown',
            'strategy' => $strategy['strategy'] ?? 'unknown',
            'file_hit' => ($strategy['correct_edit_file_found'] ?? false) ? 'yes' : 'no',
            'range_hit' => ($strategy['correct_edit_range_found'] ?? false) ? 'yes' : 'no',
            'files' => $strategy['files_opened_before_correct_location'] ?? null,
            'ranges' => $strategy['ranges_read_before_correct_range'] ?? null,
            'read_bytes' => $readBytes,
            'whole_file_bytes' => $strategy['source_bytes_read_whole_file_model'] ?? null,
            'map_bytes' => $strategy['map_output_bytes'] ?? 0,
            'tool_bytes' => $strategy['tool_output_bytes'] ?? 0,
            'candidates' => $strategy['candidate_symbols_presented'] ?? 0,
            'presented_ranges' => $strategy['exact_source_ranges_presented'] ?? 0,
            'false' => $strategy['false_candidates_opened'] ?? 0,
            'test' => ($strategy['correct_test_file_found'] ?? false) ? 'yes' : 'no',
            'commands' => $strategy['commands'] ?? 0,
            'deep_rank' => array_key_exists('deep_rank_of_verified_range', $strategy)
                ? ($strategy['deep_rank_of_verified_range'] ?? 'below ' . ($strategy['deep_rank_limit'] ?? 'unknown'))
                : 'n/a',
        ];
    }
}

$headers = ['replay', 'backend', 'strategy', 'file_hit', 'range_hit', 'files', 'ranges', 'read_bytes', 'map_bytes', 'tool_bytes', 'candidates', 'presented_ranges', 'false', 'test', 'commands', 'deep_rank'];
echo '| ' . implode(' | ', $headers) . " |\n";
echo '|' . str_repeat(' --- |', count($headers)) . "\n";
foreach ($rows as $row) {
    $cells = [];
    foreach ($headers as $header) {
        $value = $row[$header];
        $cells[] = $value === null ? 'miss' : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
    }
    echo '| ' . implode(' | ', $cells) . " |\n";
}
