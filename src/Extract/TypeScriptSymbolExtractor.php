<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\ParameterEntry;
use voku\AgentMap\Index\SymbolEntry;

/**
 * Extracts TypeScript/JavaScript declarations with the TypeScript compiler in syntax-only mode.
 *
 * One Node process parses the whole batch (`resources/typescript/extract-symbols.mjs`): no
 * program, no type checker. Symbols are named `<project-relative path>#<Name>`.
 *
 * The compiler is the `typescript` npm package at the explicit directory given here or in
 * `AGENT_MAP_TYPESCRIPT_PATH`, else the analysed project's own copy
 * (`<root>/node_modules/typescript`). Without Node or the compiler every file fails with the reason:
 * there is no fallback parser and no empty successful result.
 *
 * A file with syntax errors fails as a whole, like a PHP file with a parse error: line ranges
 * taken from an error-recovered tree are not exact source evidence.
 */
final readonly class TypeScriptSymbolExtractor implements SymbolExtractor, ProjectRootAware
{
    public const TYPESCRIPT_PATH_ENV = 'AGENT_MAP_TYPESCRIPT_PATH';

    private const HELPER = __DIR__ . '/../../resources/typescript/extract-symbols.mjs';

    public function __construct(
        private ?string $projectRoot = null,
        private ?string $typeScriptPath = null,
        private string $nodeBinary = 'node',
        private int $timeoutSeconds = 300,
    ) {
    }

    public function withProjectRoot(string $root): SymbolExtractor
    {
        return new self($root, $this->typeScriptPath, $this->nodeBinary, $this->timeoutSeconds);
    }

    public function extract(string $file): ExtractResult
    {
        return $this->extractMany([$file])[$file];
    }

    public function extractMany(array $files): array
    {
        if ($files === []) {
            return [];
        }

        $root = $this->projectRoot === null ? false : realpath($this->projectRoot);
        if (!is_string($root)) {
            return $this->failAll($files, 'TypeScript extraction needs the analysed project root to name symbols; none was configured.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');

        // An explicitly chosen compiler wins: loading the analysed project's own copy executes
        // code from that project, so it is only the fallback.
        $candidates = [];
        $explicit = $this->typeScriptPath ?? getenv(self::TYPESCRIPT_PATH_ENV);
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }
        $candidates[] = $root . '/node_modules/typescript';

        $request = json_encode([
            'root' => $root,
            'files' => array_map(static fn (string $file): string => str_replace('\\', '/', $file), $files),
            'typescriptPaths' => $candidates,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($request)) {
            return $this->failAll($files, 'Unable to encode the TypeScript extraction request.');
        }

        [$exitCode, $stdout, $stderr] = $this->run($request);
        if ($exitCode === null) {
            return $this->failAll($files, $stderr);
        }
        if ($exitCode !== 0) {
            return $this->failAll(
                $files,
                'TypeScript symbol extraction failed (exit ' . $exitCode . '): ' . (trim($stderr) !== '' ? trim($stderr) : 'no error output'),
            );
        }

        $response = json_decode($stdout, true);
        $perFile = is_array($response) ? ($response['files'] ?? null) : null;
        if (!is_array($perFile)) {
            return $this->failAll($files, 'TypeScript symbol extraction returned invalid JSON.');
        }
        $version = is_string($response['typescriptVersion'] ?? null) ? $response['typescriptVersion'] : null;

        $results = [];
        foreach ($files as $file) {
            $entry = $perFile[str_replace('\\', '/', $file)] ?? null;
            if (!is_array($entry)) {
                $results[$file] = new ExtractResult($file, false, [], 'TypeScript symbol extraction returned no result for this file.');
                continue;
            }
            if (($entry['ok'] ?? false) !== true || !is_array($entry['symbols'] ?? null)) {
                $error = $entry['error'] ?? null;
                $results[$file] = new ExtractResult($file, false, [], is_string($error) && $error !== '' ? $error : 'Unknown TypeScript extraction failure.');
                continue;
            }

            $symbols = [];
            foreach ($entry['symbols'] as $symbol) {
                if (is_array($symbol)) {
                    $symbols[] = $this->symbol($symbol);
                }
            }
            $results[$file] = new ExtractResult($file, true, $symbols, null, $version);
        }

        return $results;
    }

    /**
     * @param array<mixed> $data
     */
    private function symbol(array $data): SymbolEntry
    {
        $methods = [];
        foreach (is_array($data['methods'] ?? null) ? $data['methods'] : [] as $method) {
            if (!is_array($method)) {
                continue;
            }
            $methods[] = new MethodEntry(
                name: self::string($method['name'] ?? null),
                visibility: self::string($method['visibility'] ?? null, 'public'),
                lineStart: self::int($method['line_start'] ?? null),
                lineEnd: self::int($method['line_end'] ?? null),
                static: ($method['static'] ?? false) === true,
                abstract: ($method['abstract'] ?? false) === true,
                parameters: $this->parameters($method['parameters'] ?? null),
                nativeReturnType: self::nullableString($method['native_return_type'] ?? null),
            );
        }

        return new SymbolEntry(
            kind: self::string($data['kind'] ?? null),
            name: self::string($data['name'] ?? null),
            fqn: self::string($data['fqn'] ?? null),
            lineStart: self::int($data['line_start'] ?? null),
            lineEnd: self::int($data['line_end'] ?? null),
            methods: $methods,
            extends: self::stringList($data['extends'] ?? null),
            implements: self::stringList($data['implements'] ?? null),
            parameters: $this->parameters($data['parameters'] ?? null),
            nativeReturnType: self::nullableString($data['native_return_type'] ?? null),
            attributes: self::stringList($data['attributes'] ?? null),
        );
    }

    /**
     * @return list<ParameterEntry>
     */
    private function parameters(mixed $data): array
    {
        $parameters = [];
        foreach (is_array($data) ? $data : [] as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $parameters[] = new ParameterEntry(
                name: self::string($parameter['name'] ?? null),
                nativeType: self::nullableString($parameter['native_type'] ?? null),
                variadic: ($parameter['variadic'] ?? false) === true,
            );
        }

        return $parameters;
    }

    /**
     * Runs the helper without a shell: request on stdin, response on stdout.
     *
     * @return array{0: int|null, 1: string, 2: string} exit code (null when the process could not
     *                                                  run or timed out; the reason is then in [2]),
     *                                                  stdout, stderr
     */
    private function run(string $request): array
    {
        $unavailable = 'Unable to run Node.js ("' . $this->nodeBinary . '"), which TypeScript/JavaScript extraction requires.'
            . ' Install Node.js and make it available on PATH.';

        $helper = realpath(self::HELPER);
        if (!is_string($helper)) {
            return [null, '', 'TypeScript extraction helper is missing from the agent-map package: ' . self::HELPER];
        }

        $pipes = [];
        $process = @proc_open(
            [$this->nodeBinary, $helper],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return [null, '', $unavailable];
        }

        // All three pipes are non-blocking and share one deadline: a child that never reads its
        // stdin, or exits before reading it, must end as a batch failure, not as a hang or a notice.
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $pending = $request;
        $stdin = $pipes[0];
        $output = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + $this->timeoutSeconds;
        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                foreach ([$stdin, ...array_values($open)] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_terminate($process, 9);
                proc_close($process);

                return [null, '', 'TypeScript symbol extraction timed out after ' . $this->timeoutSeconds . ' second(s).'];
            }

            $read = array_values($open);
            $write = $stdin === null ? null : [$stdin];
            $except = null;
            if (@stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000)) === false) {
                continue;
            }
            if ($stdin !== null && in_array($stdin, (array) $write, true)) {
                // select() reported the pipe writable, so a write of nothing means its reader is
                // gone (EPIPE). The exit code and stderr read below say why the child left.
                $written = @fwrite($stdin, $pending);
                $pending = $written === false || $written === 0 ? '' : substr($pending, $written);
                if ($pending === '') {
                    fclose($stdin);
                    $stdin = null;
                }
            }
            foreach ($open as $descriptor => $pipe) {
                if (!in_array($pipe, $read, true)) {
                    continue;
                }
                $chunk = fread($pipe, 65_536);
                if (is_string($chunk)) {
                    $output[$descriptor] .= $chunk;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$descriptor]);
                }
            }
        }
        if ($stdin !== null) {
            fclose($stdin);
        }

        $exitCode = proc_close($process);
        if ($exitCode === 127) {
            return [null, '', $unavailable];
        }

        return [$exitCode, $output[1], $output[2]];
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, ExtractResult>
     */
    private function failAll(array $files, string $error): array
    {
        $results = [];
        foreach ($files as $file) {
            $results[$file] = new ExtractResult($file, false, [], $error);
        }

        return $results;
    }

    private static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
