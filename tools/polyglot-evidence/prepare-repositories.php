<?php

declare(strict_types=1);

/** @return array<string, string> */
function parseOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Expected --name=value argument, got: ' . $argument);
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || $value === '') {
            throw new InvalidArgumentException('Expected non-empty --name=value argument, got: ' . $argument);
        }

        $options[$name] = $value;
    }

    return $options;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('JSON file does not exist: ' . $path);
    }

    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read JSON file: ' . $path);
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object in: ' . $path);
    }

    return $decoded;
}

/** @param list<string> $command */
function runCommand(array $command, ?string $cwd = null): string
{
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cwd,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start command: ' . implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if (!is_string($stdout) || !is_string($stderr)) {
        throw new RuntimeException('Could not read command output: ' . implode(' ', $command));
    }
    if ($exit !== 0) {
        throw new RuntimeException(
            'Command failed (' . $exit . '): ' . implode(' ', $command) . PHP_EOL . $stderr,
        );
    }

    return trim($stdout);
}

/** @param array<string, mixed> $row */
function requiredString(array $row, string $key, string $context): string
{
    $value = $row[$key] ?? null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException($context . ' requires non-empty string field "' . $key . '".');
    }

    return $value;
}

try {
    $options = parseOptions($argv);
    $corpusPath = $options['corpus'] ?? null;
    $workdir = $options['workdir'] ?? null;
    $manifestPath = $options['manifest'] ?? null;
    if ($corpusPath === null || $workdir === null || $manifestPath === null) {
        throw new InvalidArgumentException(
            'Usage: --corpus=<corpus.json> --workdir=<directory> --manifest=<repositories.json>',
        );
    }

    $corpus = readJson($corpusPath);
    if (($corpus['schema'] ?? null) !== 'agent-map-polyglot-corpus@1') {
        throw new RuntimeException('Unsupported corpus schema in: ' . $corpusPath);
    }

    $repositories = $corpus['repositories'] ?? null;
    if (!is_array($repositories) || $repositories === []) {
        throw new RuntimeException('Corpus requires at least one repository.');
    }

    if (!is_dir($workdir) && !mkdir($workdir, 0o775, true) && !is_dir($workdir)) {
        throw new RuntimeException('Could not create work directory: ' . $workdir);
    }

    $manifest = [
        'schema' => 'agent-map-polyglot-repositories@1',
        'repositories' => [],
    ];

    foreach ($repositories as $offset => $repository) {
        if (!is_array($repository)) {
            throw new RuntimeException('Repository #' . $offset . ' must be an object.');
        }

        $context = 'Repository #' . $offset;
        $id = requiredString($repository, 'id', $context);
        $url = requiredString($repository, 'url', $context);
        $commit = requiredString($repository, 'commit', $context);
        $language = requiredString($repository, 'language', $context);
        $path = rtrim($workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id;

        if (file_exists($path)) {
            throw new RuntimeException('Repository destination already exists: ' . $path);
        }
        if (!mkdir($path, 0o775, true)) {
            throw new RuntimeException('Could not create repository destination: ' . $path);
        }

        runCommand(['git', 'init', '--quiet'], $path);
        runCommand(['git', 'remote', 'add', 'origin', $url], $path);
        runCommand(['git', 'fetch', '--quiet', '--depth=1', 'origin', $commit], $path);
        runCommand(['git', 'checkout', '--quiet', '--detach', 'FETCH_HEAD'], $path);

        $actual = runCommand(['git', 'rev-parse', 'HEAD'], $path);
        if ($actual !== $commit) {
            throw new RuntimeException(
                sprintf('Pinned commit mismatch for %s: expected %s, got %s.', $id, $commit, $actual),
            );
        }

        $manifest['repositories'][$id] = [
            'path' => $path,
            'commit' => $commit,
            'language' => $language,
        ];
    }

    $manifestDir = dirname($manifestPath);
    if (!is_dir($manifestDir) && !mkdir($manifestDir, 0o775, true) && !is_dir($manifestDir)) {
        throw new RuntimeException('Could not create manifest directory: ' . $manifestDir);
    }

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($manifestPath, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Could not write repository manifest: ' . $manifestPath);
    }

    fwrite(STDOUT, $manifestPath . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
