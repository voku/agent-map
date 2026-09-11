<?php

declare(strict_types=1);

namespace voku\AgentMap\Evidence;

use JsonException;
use RuntimeException;
use Throwable;

final readonly class ScipDefinitionProvider
{
    public function lookup(string $root, string $indexFile, string $symbol): DefinitionEvidence
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $project = $this->projectKind($root);
        if ($project === null) {
            return DefinitionEvidence::unavailable(
                'SCIP definition lookup supports one detected JavaScript/TypeScript or Python project in this first slice.',
            );
        }
        if ($project === 'mixed') {
            return DefinitionEvidence::unavailable(
                'Both JavaScript/TypeScript and Python project markers are present; the first SCIP slice does not guess which semantic index should answer.',
            );
        }

        $scip = $this->executable('scip');
        $indexerName = $project === 'python' ? 'scip-python' : 'scip-typescript';
        $indexer = $this->executable($indexerName);
        if ($scip === null || $indexer === null) {
            $missing = [];
            if ($scip === null) {
                $missing[] = 'scip';
            }
            if ($indexer === null) {
                $missing[] = $indexerName;
            }

            return DefinitionEvidence::unavailable('Missing executable(s): ' . implode(', ', $missing) . '.');
        }

        $toolchain = [
            'scip' => $this->version($scip, $root),
            $indexerName => $this->version($indexer, $root),
        ];

        try {
            $directory = dirname($indexFile);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                return DefinitionEvidence::error('Unable to create SCIP artifact directory: ' . $directory, $toolchain);
            }
            if (is_file($indexFile) && !unlink($indexFile)) {
                return DefinitionEvidence::error('Unable to replace stale SCIP artifact: ' . $indexFile, $toolchain);
            }

            $config = null;
            try {
                $command = $project === 'python'
                    ? [$indexer, 'index', '--cwd', $root, '--project-name', basename($root), '--output', $indexFile, '--quiet']
                    : [$indexer, 'index', '--output', $indexFile, $config = $this->javascriptConfig($root)];
                $buildStarted = microtime(true);
                $built = $this->run($command, $root);
                $buildMilliseconds = (microtime(true) - $buildStarted) * 1000;
                if ($built['exit'] !== 0) {
                    return DefinitionEvidence::error(
                        $indexerName . ' failed with exit ' . $built['exit'] . ': ' . $this->failureText($built),
                        $toolchain,
                    );
                }
            } finally {
                if ($config !== null && str_contains(basename($config), '.agent-map-scip-') && is_file($config)) {
                    unlink($config);
                }
            }

            if (!is_file($indexFile)) {
                return DefinitionEvidence::error($indexerName . ' reported success without creating ' . $indexFile . '.', $toolchain);
            }

            $indexBytes = filesize($indexFile);
            if (!is_int($indexBytes)) {
                return DefinitionEvidence::error('Unable to measure SCIP artifact: ' . $indexFile, $toolchain);
            }

            $materializeStarted = microtime(true);
            $printed = $this->run([$scip, 'print', '--json', $indexFile], $root);
            $materializeMilliseconds = (microtime(true) - $materializeStarted) * 1000;
            if ($printed['exit'] !== 0) {
                return DefinitionEvidence::error(
                    'scip print failed with exit ' . $printed['exit'] . ': ' . $this->failureText($printed),
                    $toolchain,
                );
            }

            $definitions = $this->definitions($printed['stdout'], $symbol);
            $metrics = [
                'build_ms' => round($buildMilliseconds, 1),
                'materialize_ms' => round($materializeMilliseconds, 1),
                'index_bytes' => $indexBytes,
            ];

            return $definitions === []
                ? DefinitionEvidence::notFound($toolchain, $metrics)
                : DefinitionEvidence::answered($definitions, $toolchain, $metrics);
        } catch (Throwable $throwable) {
            return DefinitionEvidence::error($throwable->getMessage(), $toolchain);
        }
    }

    /** @return 'javascript'|'python'|'mixed'|null */
    private function projectKind(string $root): ?string
    {
        $javascript = is_file($root . '/package.json')
            || is_file($root . '/tsconfig.json')
            || is_file($root . '/jsconfig.json');
        $python = is_file($root . '/pyproject.toml')
            || is_file($root . '/setup.py')
            || is_file($root . '/setup.cfg');

        if ($javascript && $python) {
            return 'mixed';
        }
        if ($javascript) {
            return 'javascript';
        }
        if ($python) {
            return 'python';
        }

        return null;
    }

    private function javascriptConfig(string $root): string
    {
        foreach (['tsconfig.json', 'jsconfig.json'] as $candidate) {
            if (is_file($root . '/' . $candidate)) {
                return $root . '/' . $candidate;
            }
        }

        $path = $root . '/.agent-map-scip-' . bin2hex(random_bytes(6)) . '.json';
        $payload = json_encode([
            'compilerOptions' => [
                'allowJs' => true,
                'checkJs' => false,
                'skipLibCheck' => true,
            ],
            'include' => ['**/*.js', '**/*.cjs', '**/*.mjs', '**/*.jsx', '**/*.ts', '**/*.tsx'],
            'exclude' => ['node_modules/**', 'dist/**', 'build/**', 'coverage/**', '.agent-map/**'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $payload . "\n") === false) {
            throw new RuntimeException('Unable to write temporary SCIP JavaScript config: ' . $path);
        }

        return $path;
    }

    private function executable(string $name): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function version(string $executable, string $root): string
    {
        $result = $this->run([$executable, '--version'], $root);
        $text = trim($result['stdout'] !== '' ? $result['stdout'] : $result['stderr']);

        return $result['exit'] === 0 && $text !== '' ? strtok($text, "\n") ?: 'unknown' : 'unknown';
    }

    /**
     * @param list<string> $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function run(array $command, string $root): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start: ' . implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @param array{exit: int, stdout: string, stderr: string} $result */
    private function failureText(array $result): string
    {
        $text = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

        return $text !== '' ? $text : 'no diagnostic output';
    }

    /** @return list<DefinitionLocation> */
    private function definitions(string $json, string $name): array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON from scip print: ' . $exception->getMessage(), previous: $exception);
        }
        if (!is_array($payload) || !is_array($payload['documents'] ?? null)) {
            throw new RuntimeException('Invalid SCIP JSON: missing documents array.');
        }

        $definitions = [];
        foreach ($payload['documents'] as $document) {
            if (!is_array($document)) {
                continue;
            }
            $file = $document['relative_path'] ?? $document['relativePath'] ?? null;
            $occurrences = $document['occurrences'] ?? null;
            if (!is_string($file) || !is_array($occurrences)) {
                continue;
            }

            foreach ($occurrences as $occurrence) {
                if (!is_array($occurrence)) {
                    continue;
                }
                $symbol = $occurrence['symbol'] ?? null;
                $roles = $occurrence['symbol_roles'] ?? $occurrence['symbolRoles'] ?? 0;
                if (!is_string($symbol) || !is_numeric($roles) || (((int) $roles) & 1) !== 1 || !$this->matchesName($symbol, $name)) {
                    continue;
                }

                [$lineStart, $lineEnd] = $this->lineRange($occurrence['range'] ?? null);
                $key = $symbol . "\0" . $file . "\0" . $lineStart . "\0" . $lineEnd;
                $definitions[$key] = new DefinitionLocation(
                    symbolId: $symbol,
                    file: str_replace('\\', '/', $file),
                    lineStart: $lineStart,
                    lineEnd: $lineEnd,
                );
            }
        }

        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }

    private function matchesName(string $symbol, string $name): bool
    {
        $escaped = preg_quote($name, '/');

        // A SCIP child symbol contains every owner descriptor. Matching any
        // descriptor therefore turns `function().(parameter)` into another
        // definition of `function`. The queried descriptor must be terminal.
        return preg_match('/(?:^|[\/ ])' . $escaped . '(?:#|\(\)\.|\.)\z/', $symbol) === 1;
    }

    /** @return array{0: int, 1: int} */
    private function lineRange(mixed $range): array
    {
        if (!is_array($range) || !array_is_list($range)) {
            throw new RuntimeException('Invalid SCIP definition occurrence: missing range.');
        }
        if (count($range) === 3 && is_int($range[0])) {
            return [$range[0] + 1, $range[0] + 1];
        }
        if (count($range) === 4 && is_int($range[0]) && is_int($range[2])) {
            return [$range[0] + 1, $range[2] + 1];
        }

        throw new RuntimeException('Invalid SCIP definition occurrence range.');
    }
}
