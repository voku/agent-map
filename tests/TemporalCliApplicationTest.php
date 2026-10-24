<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Cli\TemporalCliApplication;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\MapArtifactPaths;

final class TemporalCliApplicationTest extends TestCase
{
    public function testHistoryDiffEmitsMachineReadableStructuralEvents(): void
    {
        $directory = sys_get_temp_dir() . '/agent-map-temporal-cli-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o775, true));
        $beforePath = $directory . '/before.json';
        $afterPath = $directory . '/after.json';
        $writer = new IndexWriter();
        $writer->write($this->map([]), $beforePath, 'json');
        $writer->write($this->map([new MethodEntry('run', 'public', 10, 20)]), $afterPath, 'json');

        try {
            ob_start();
            $status = (new TemporalCliApplication())->run([
                'agent-map',
                'history',
                'diff',
                '--before=' . $beforePath,
                '--after=' . $afterPath,
                '--format=json',
            ]);
            $output = ob_get_clean();

            self::assertSame(0, $status);
            self::assertIsString($output);
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('history_diff', $payload['type'] ?? null);
            self::assertSame(1, $payload['counts']['method_added'] ?? null);

            $methodEvents = array_values(array_filter(
                is_array($payload['events'] ?? null) ? $payload['events'] : [],
                static fn (mixed $event): bool => is_array($event) && ($event['kind'] ?? null) === 'method_added',
            ));
            self::assertCount(1, $methodEvents);
            self::assertSame('method:App\\Foo::run', $methodEvents[0]['entity_id'] ?? null);
        } finally {
            @unlink($beforePath);
            @unlink($afterPath);
            @rmdir($directory);
        }
    }

    public function testHistoryShowDoesNotCreateMissingDatabase(): void
    {
        $database = sys_get_temp_dir() . '/agent-map-missing-history-' . bin2hex(random_bytes(6)) . '.sqlite';
        @unlink($database);

        $status = (new TemporalCliApplication())->run([
            'agent-map',
            'history',
            'show',
            'method:App\\Foo::run',
            '--database=' . $database,
        ]);

        self::assertSame(1, $status);
        self::assertFileDoesNotExist($database);
    }

    public function testHistoryObserveCanSelectTheRootOfAnHistoricalIndex(): void
    {
        $directory = sys_get_temp_dir() . '/agent-map-temporal-root-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o775, true));
        $root = $directory . '/historical-root';
        $selectedRoot = $directory . '/selected-root';
        self::assertTrue(mkdir($root, 0o775, true));
        self::assertTrue(mkdir($selectedRoot, 0o775, true));
        $this->git($root, ['init', '--quiet']);
        $this->git($root, ['-c', 'user.name=Agent Map Test', '-c', 'user.email=agent-map-test@example.invalid', 'commit', '--allow-empty', '--quiet', '-m', 'baseline']);
        $index = $directory . '/before.json';
        $database = $directory . '/history.sqlite';
        (new IndexWriter())->write(new AgentMapIndex('2.0', $root, 'test', []), $index, 'json');

        try {
            ob_start();
            $status = (new TemporalCliApplication(artifacts: MapArtifactPaths::forProject($selectedRoot)))->run([
                'agent-map',
                'history',
                'observe',
                '--index=' . $index,
                '--root=' . $root,
                '--database=' . $database,
                '--format=json',
            ]);
            $output = ob_get_clean();

            self::assertSame(0, $status);
            self::assertIsString($output);
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('history_observe', $payload['type'] ?? null);
            self::assertSame(1, $payload['snapshot_count'] ?? null);
        } finally {
            $this->removeTree($directory);
        }
    }

    public function testSupportsHistoryAndHelpRouting(): void
    {
        $application = new TemporalCliApplication();

        self::assertTrue($application->supports(['agent-map', 'history', 'diff']));
        self::assertTrue($application->supports(['agent-map', 'help', 'history']));
        self::assertFalse($application->supports(['agent-map', 'discover']));
    }

    /** @param list<MethodEntry> $methods */
    private function map(array $methods): AgentMapIndex
    {
        return new AgentMapIndex(
            '2.0',
            sys_get_temp_dir(),
            'test',
            [new FileEntry(
                'src/Foo.php',
                'sha256:' . hash('sha256', serialize(array_map(static fn (MethodEntry $method): array => $method->toArray(), $methods))),
                'App',
                [new SymbolEntry('class', 'Foo', 'App\\Foo', 1, 100, $methods)],
            )],
        );
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): void
    {
        $process = proc_open(['git', '-C', $root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start git.');
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new \RuntimeException(trim((string) $stderr) ?: 'git failed');
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
