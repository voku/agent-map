<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use RuntimeException;
use Throwable;
use voku\AgentMap\Backend\MagoAstBackend;
use voku\AgentMap\Backend\TokenAstBackend;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;

final readonly class AgentMapApplication
{
    public function __construct(
        private OutputFormatter $formatter = new OutputFormatter(),
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        array_shift($argv);

        try {
            $options = CliOptions::parse($argv);
            if ($options->command === 'help' || $options->help) {
                echo $this->help($options->command);
                return 0;
            }

            return match ($options->command) {
                'build' => $this->build($options),
                'query' => $this->query($options),
                'file' => $this->file($options),
                'stale' => $this->stale($options),
                'summary' => $this->summary($options),
                'changed' => $this->changed($options),
                'related' => $this->related($options),
                'stats' => $this->stats($options),
                default => 1,
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    private function build(CliOptions $options): int
    {
        $backend = $options->backend === 'mago' ? new MagoAstBackend() : new TokenAstBackend();
        $index = (new AgentMapBuilder())->build($options->root, $options->paths, $options->excludes, $options->backend, $backend, $options->workers);
        (new IndexWriter())->write($index, $options->out);
        echo 'Wrote ' . count($index->files) . ' file(s) to ' . $options->out . "\n";

        return 0;
    }

    private function query(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        $files = array_slice($index->query((string) $options->argument), 0, $options->limit);
        echo $this->formatter->render([
            'type' => 'query',
            'title' => (string) $options->argument,
            'query' => (string) $options->argument,
            'files' => $this->formatter->filesPayload($files, $options->symbolLimit, $options->methodLimit),
            'include_namespace' => false,
        ], $options->format);

        return 0;
    }

    private function file(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $file = $index->file((string) $options->argument);
        if ($file === null) {
            fwrite(STDERR, 'File not found in index: ' . $options->argument . "\n");
            return 1;
        }

        $this->warnIfStale($index->staleEntries());
        echo $this->formatter->render([
            'type' => 'file',
            'title' => $file->path,
            'files' => [$this->formatter->filePayload($file, $options->symbolLimit, $options->methodLimit)],
            'include_namespace' => true,
        ], $options->format);

        return 0;
    }

    private function stale(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $stale = $index->staleEntries();
        if ($stale === []) {
            echo "OK\n";
            return 0;
        }

        foreach ($stale as $entry) {
            echo 'STALE ' . $entry['path'] . ' ' . $entry['reason'] . "\n";
        }

        return 1;
    }

    private function summary(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        echo $this->formatter->render([
            'type' => 'summary',
            'title' => 'Agent Map Summary',
            ...$index->summaryCounts(),
            'top_namespaces' => $index->topNamespaces(5),
            'top_directories' => $index->topDirectories(5),
            'entrypoints' => $this->entrypoints($index->root),
        ], $options->format);

        return 0;
    }

    private function stats(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        echo $this->formatter->render([
            'type' => 'stats',
            'title' => 'Agent Map Stats',
            'files' => count($index->files),
            'symbols' => $index->summaryCounts()['symbols'],
            'methods' => $index->methodCount(),
            'index_size' => $this->humanSize($options->index),
            'largest_files' => $index->largestFiles($options->limit),
        ], $options->format);

        return 0;
    }

    private function changed(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        $changed = $this->changedPhpFiles($index->root, $options->base);
        $files = [];
        $unindexed = [];
        foreach ($changed as $path) {
            $file = $index->file($path);
            if ($file === null) {
                $unindexed[] = $path;
            } else {
                $files[] = $file;
            }
        }

        echo $this->formatter->render([
            'type' => 'changed',
            'title' => 'Changed PHP files',
            'base' => $options->base,
            'files' => $this->formatter->filesPayload(array_slice($files, 0, $options->limit), $options->symbolLimit, $options->methodLimit),
            'unindexed' => array_slice($unindexed, 0, $options->limit),
        ], $options->format);

        return $changed === [] ? 1 : 0;
    }

    private function related(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        $primary = array_slice($index->query((string) $options->argument), 0, $options->limit);
        $first = $primary[0] ?? null;
        $likelyTests = $first === null ? [] : $index->likelyTestFiles($first, $options->limit);
        $sameNamespace = $first === null ? [] : $index->sameNamespaceFiles($first, $options->limit);
        $mentions = $this->mentionFiles($index, (string) $options->argument, $primary, $options->limit);

        echo $this->formatter->render([
            'type' => 'related',
            'title' => 'Related: ' . (string) $options->argument,
            'query' => (string) $options->argument,
            'primary' => $this->formatter->filesPayload($primary, $options->symbolLimit, $options->methodLimit),
            'likely_tests' => $this->formatter->filesPayload($likelyTests, $options->symbolLimit, $options->methodLimit),
            'same_namespace' => $this->formatter->filesPayload($sameNamespace, $options->symbolLimit, $options->methodLimit),
            'mentions' => $this->formatter->filesPayload($mentions, $options->symbolLimit, $options->methodLimit),
        ], $options->format);

        return $primary === [] ? 1 : 0;
    }

    /**
     * @param list<array{path: string, reason: string}> $stale
     */
    private function warnIfStale(array $stale): void
    {
        if ($stale !== []) {
            fwrite(STDERR, "WARNING: index is stale. Rebuild it with agent-map build.\n");
        }
    }

    /**
     * @return list<string>
     */
    private function entrypoints(string $root): array
    {
        $entrypoints = [];
        foreach (glob($root . '/bin/*') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'bin/' . basename($file);
            }
        }

        foreach (glob($root . '/src/*Cli*.php') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'src/' . basename($file);
            }
        }

        foreach (glob($root . '/scripts/private/*cli*.php') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'scripts/private/' . basename($file);
            }
        }

        foreach (glob($root . '/scripts/private/*_cli.php') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'scripts/private/' . basename($file);
            }
        }

        $entrypoints = array_values(array_unique($entrypoints));
        sort($entrypoints);

        return array_slice($entrypoints, 0, 10);
    }

    /**
     * @return list<string>
     */
    private function changedPhpFiles(string $root, string $base): array
    {
        if (!is_dir($root . '/.git')) {
            throw new RuntimeException('Changed requires a Git repository at index root: ' . $root);
        }

        $files = [
            ...$this->gitChangedPhpFiles($root, ['diff', '--name-only', $base . '...HEAD', '--', '*.php'], true),
            ...$this->gitChangedPhpFiles($root, ['diff', '--name-only', '--', '*.php']),
            ...$this->gitChangedPhpFiles($root, ['diff', '--cached', '--name-only', '--', '*.php']),
            ...$this->gitChangedPhpFiles($root, ['ls-files', '--others', '--exclude-standard', '--', '*.php']),
        ];

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function gitChangedPhpFiles(string $root, array $args, bool $allowFailure = false): array
    {
        $process = proc_open(
            ['git', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start git diff.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            if ($allowFailure) {
                fwrite(STDERR, 'WARNING: git diff failed for base comparison; continuing with working-tree changes.' . "\n");

                return [];
            }

            throw new RuntimeException(trim((string) $stderr) ?: 'git diff failed');
        }

        $files = [];
        foreach (explode("\n", trim((string) $stdout)) as $line) {
            $line = trim($line);
            if ($line !== '' && str_ends_with($line, '.php')) {
                $files[] = str_replace('\\', '/', $line);
            }
        }

        return $files;
    }

    /**
     * @param list<FileEntry> $primary
     * @return list<FileEntry>
     */
    private function mentionFiles(AgentMapIndex $index, string $term, array $primary, int $limit): array
    {
        $primaryPaths = array_map(static fn (FileEntry $file): string => $file->path, $primary);
        $matches = [];
        foreach ($index->files as $file) {
            if (in_array($file->path, $primaryPaths, true)) {
                continue;
            }

            $absolute = $index->root . '/' . $file->path;
            if (is_file($absolute) && str_contains((string) file_get_contents($absolute), $term)) {
                $matches[] = $file;
            }

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    private function humanSize(string $path): string
    {
        if (!is_file($path)) {
            return 'unknown';
        }

        $bytes = filesize($path);
        if (!is_int($bytes)) {
            return 'unknown';
        }

        return $bytes < 1024 ? $bytes . ' B' : round($bytes / 1024, 1) . ' KB';
    }

    private function help(string $command): string
    {
        if ($command === 'build') {
            return <<<'TXT'
            Usage:
              agent-map build [--root=.] [--paths=src,tests] [--out=.agent-map/php-symbols.json] [--backend=token|mago] [--exclude=REGEX]

            Build a compact PHP symbol index. --exclude is repeatable.
            TXT;
        }

        return <<<'TXT'
        agent-map - compact PHP symbol maps for coding agents

        Usage:
          agent-map build --root=. --paths=src,tests --out=.agent-map/php-symbols.json --backend=token
          agent-map query EvidenceValidator --index=.agent-map/php-symbols.json
          agent-map file src/EvidenceValidator.php --index=.agent-map/php-symbols.json
          agent-map stale --index=.agent-map/php-symbols.json
          agent-map summary --index=.agent-map/php-symbols.json
          agent-map changed --index=.agent-map/php-symbols.json --base=main
          agent-map related EvidenceValidator --index=.agent-map/php-symbols.json
          agent-map stats --index=.agent-map/php-symbols.json
          agent-map help

        Options:
          --format=text|json|markdown|toon
          --limit=20
          --symbol-limit=10
          --method-limit=10

        TXT;
    }
}
