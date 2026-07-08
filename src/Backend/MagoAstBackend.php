<?php

declare(strict_types=1);

namespace voku\AgentMap\Backend;

use Throwable;

/**
 * Runs `mago ast` as an external parse gate.
 *
 * Each file requires its own process (mago has no batch/stdin mode), so for
 * large repositories parsing is dispatched through a bounded worker pool
 * instead of one process at a time — see parseMany().
 *
 * `mago ast --json` exits 0 even when the file has syntax errors: it prints
 * human-readable diagnostics to stdout followed by the JSON AST, and the
 * exit code only reflects things like a missing file. So the exit code
 * cannot be used to detect parse failures — instead we read the trailing
 * JSON and check `program.errors`, which mago always populates.
 */
final readonly class MagoAstBackend implements AstBackend, ParallelAstBackend
{
    public function __construct(
        private string $binary = 'mago',
    ) {
    }

    public function parse(string $file): AstResult
    {
        return $this->parseMany([$file], 1)[$file];
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, AstResult>
     */
    public function parseMany(array $files, int $workers): array
    {
        $workers = max(1, $workers);
        $pending = $files;
        $results = [];
        /** @var list<array{file: string, process: resource, stdout: resource, stderr: resource, out: string, err: string}> $running */
        $running = [];

        $signals = $this->trapInterrupts($running);

        try {
            while ($pending !== [] || $running !== []) {
                while (count($running) < $workers && $pending !== []) {
                    $file = array_shift($pending);
                    $started = $this->start($file);
                    if ($started === null) {
                        $results[$file] = new AstResult($file, false, 'Unable to start mago process.');
                        continue;
                    }

                    $running[] = $started;
                }

                if ($running === []) {
                    continue;
                }

                $stillRunning = [];
                foreach ($running as $entry) {
                    $entry['out'] .= (string) stream_get_contents($entry['stdout']);
                    $entry['err'] .= (string) stream_get_contents($entry['stderr']);

                    $status = proc_get_status($entry['process']);
                    if ($status['running']) {
                        $stillRunning[] = $entry;
                        continue;
                    }

                    $results[$entry['file']] = $this->finish($entry, $status['exitcode']);
                }

                $running = $stillRunning;
                if ($running !== []) {
                    usleep(1_000);
                }
            }
        } finally {
            $this->releaseInterrupts($signals);
        }

        return $results;
    }

    /**
     * Without this, a killed (Ctrl-C'd) build leaves its in-flight `mago`
     * children running as orphans instead of exiting with the parent.
     *
     * @param list<array{file: string, process: resource, stdout: resource, stderr: resource, out: string, err: string}> $running
     *
     * @return array<int, mixed>|null previous signal handlers keyed by signal number, or null if pcntl is unavailable
     */
    private function trapInterrupts(array &$running): ?array
    {
        if (!function_exists('pcntl_async_signals')) {
            return null;
        }

        pcntl_async_signals(true);
        $previous = [
            SIGINT => pcntl_signal_get_handler(SIGINT),
            SIGTERM => pcntl_signal_get_handler(SIGTERM),
        ];

        $handler = static function (int $signal) use (&$running): void {
            foreach ($running as $entry) {
                proc_terminate($entry['process']);
            }

            exit(128 + $signal);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);

        return $previous;
    }

    /**
     * @param array<int, mixed>|null $previous
     */
    private function releaseInterrupts(?array $previous): void
    {
        if ($previous === null) {
            return;
        }

        foreach ($previous as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    /**
     * @return array{file: string, process: resource, stdout: resource, stderr: resource, out: string, err: string}|null
     */
    private function start(string $file): ?array
    {
        $command = [$this->binary, 'ast', $file, '--json'];
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $process = @proc_open($command, $descriptorSpec, $pipes);
        } catch (Throwable) {
            return null;
        }

        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'file' => $file,
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'out' => '',
            'err' => '',
        ];
    }

    /**
     * @param array{file: string, process: resource, stdout: resource, stderr: resource, out: string, err: string} $entry
     */
    private function finish(array $entry, int $exitCode): AstResult
    {
        $entry['out'] .= (string) stream_get_contents($entry['stdout']);
        $entry['err'] .= (string) stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        proc_close($entry['process']);

        if ($exitCode !== 0) {
            return new AstResult(
                file: $entry['file'],
                ok: false,
                error: trim($entry['err']) ?: 'mago ast failed',
            );
        }

        return $this->gate($entry['file'], $entry['out']);
    }

    private function gate(string $file, string $stdout): AstResult
    {
        $jsonStart = strpos($stdout, '{');
        if ($jsonStart === false) {
            return new AstResult($file, false, trim($stdout) ?: 'mago produced no output');
        }

        $decoded = json_decode(substr($stdout, $jsonStart), true);
        if (!is_array($decoded)) {
            return new AstResult($file, false, 'mago produced invalid JSON output');
        }

        $errors = $decoded['program']['errors'] ?? [];
        if ($errors !== []) {
            $diagnostics = trim(substr($stdout, 0, $jsonStart));

            return new AstResult($file, false, $diagnostics !== '' ? $diagnostics : 'mago reported parse errors');
        }

        return new AstResult($file, true);
    }
}
