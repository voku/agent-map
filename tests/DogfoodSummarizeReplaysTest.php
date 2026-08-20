<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The summary boundary must not turn contradictory evidence into a normal-looking table row.
 */
final class DogfoodSummarizeReplaysTest extends TestCase
{
    private string $reports;

    protected function setUp(): void
    {
        $this->reports = sys_get_temp_dir() . '/agent-map-dogfood-summary-' . bin2hex(random_bytes(8));
        mkdir($this->reports, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->reports . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->reports)) {
            rmdir($this->reports);
        }
    }

    public function testConsistentReportBecomesATableRow(): void
    {
        $this->writeReport('valid.json', 'structural', 'simple-php-code-parser+structural-only');

        $result = $this->summarize();

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('| demo | structural | A_baseline_grep_read |', $result['output']);
    }

    public function testContradictoryReportFailsTheSummaryInsteadOfBeingRendered(): void
    {
        $this->writeReport('portable-ascii-135-phpstan.json', 'phpstan', 'simple-php-code-parser+structural-only');

        $result = $this->summarize();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('requested the "phpstan" backend', $result['output']);
        self::assertStringNotContainsString('A_baseline_grep_read', $result['output']);
    }

    public function testUnrelatedJsonIsSkippedQuietly(): void
    {
        file_put_contents($this->reports . '/composer-like.json', '{"name":"not-a-replay-report"}');
        file_put_contents($this->reports . '/broken.json', '{not json');
        $this->writeReport('valid.json', 'structural', 'simple-php-code-parser+structural-only');

        $result = $this->summarize();

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('| demo | structural |', $result['output']);
    }

    private function writeReport(string $name, string $requested, string $effective): void
    {
        $report = [
            'schema_version' => '1.0',
            'replay' => ['id' => 'demo', 'shape' => 'local-method'],
            'observation_envelope' => [
                'requested_backend' => $requested,
                'backend' => $effective,
            ],
            'strategies' => [
                [
                    'strategy' => 'A_baseline_grep_read',
                    'commands' => 3,
                    'map_output_bytes' => 0,
                    'tool_output_bytes' => 10,
                    'correct_edit_file_found' => true,
                    'correct_edit_range_found' => true,
                    'files_opened_before_correct_location' => 1,
                    'ranges_read_before_correct_range' => 1,
                    'source_bytes_read_window_model' => 42,
                ],
            ],
        ];

        file_put_contents(
            $this->reports . '/' . $name,
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{status: int, output: string}
     */
    private function summarize(): array
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../tools/dogfood/summarize-replays.php')
            . ' --reports=' . escapeshellarg($this->reports)
            . ' 2>&1';

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        return ['status' => $status, 'output' => implode("\n", $output)];
    }
}
