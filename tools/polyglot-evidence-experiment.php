<?php

declare(strict_types=1);

/**
 * Score normalized polyglot evidence-provider results against a pinned corpus.
 *
 * Usage:
 *   php tools/polyglot-evidence-experiment.php \
 *     --corpus=tools/polyglot-evidence/corpus.json \
 *     --results=/tmp/rg.json,/tmp/sigmap.json,/tmp/tree-sitter.json,/tmp/scip.json
 */

final readonly class ExperimentTask
{
    /**
     * @param non-empty-list<non-empty-string> $expectedPaths
     * @param list<non-empty-string> $expectedRelations
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $expectedPaths,
        public array $expectedRelations,
    ) {
    }
}

final readonly class ProviderTaskResult
{
    /**
     * @param list<non-empty-string> $paths
     * @param list<non-empty-string> $relations
     */
    public function __construct(
        public string $id,
        public string $status,
        public array $paths,
        public array $relations,
        public ?float $elapsedMs,
    ) {
    }
}

final readonly class ProviderResult
{
    /**
     * @param array<non-empty-string, ProviderTaskResult> $tasks
     * @param array<string, int|float|string|null> $metadata
     */
    public function __construct(
        public string $provider,
        public string $version,
        public array $tasks,
        public array $metadata,
    ) {
    }
}

final readonly class Score
{
    public function __construct(
        public string $provider,
        public string $version,
        public int $tasks,
        public int $supported,
        public int $unavailable,
        public int $errors,
        public int $hitAt1,
        public int $hitAt5,
        public int $falseAbsences,
        public int $relationTruePositive,
        public int $relationReturned,
        public int $relationExpected,
        public ?float $meanQueryMs,
        public int|float|null $coldIndexMs,
        public int|float|null $warmIndexMs,
        public int|float|null $indexBytes,
    ) {
    }

    public function coverageRate(): float
    {
        return $this->tasks === 0 ? 0.0 : $this->supported / $this->tasks;
    }

    public function hitAt1Rate(): float
    {
        return $this->tasks === 0 ? 0.0 : $this->hitAt1 / $this->tasks;
    }

    public function hitAt5Rate(): float
    {
        return $this->tasks === 0 ? 0.0 : $this->hitAt5 / $this->tasks;
    }

    public function falseAbsenceRate(): float
    {
        return $this->tasks === 0 ? 0.0 : $this->falseAbsences / $this->tasks;
    }

    public function relationPrecision(): ?float
    {
        return $this->relationReturned === 0 ? null : $this->relationTruePositive / $this->relationReturned;
    }

    public function relationRecall(): ?float
    {
        return $this->relationExpected === 0 ? null : $this->relationTruePositive / $this->relationExpected;
    }
}

/** @return array<string, mixed> */
function decodeJsonFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('JSON file does not exist: ' . $path);
    }

    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read JSON file: ' . $path);
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Invalid JSON in ' . $path . ': ' . $exception->getMessage(), previous: $exception);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object in: ' . $path);
    }

    return $decoded;
}

/** @return non-empty-string */
function requiredString(array $row, string $key, string $context): string
{
    $value = $row[$key] ?? null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException($context . ' requires non-empty string field "' . $key . '".');
    }

    return $value;
}

/** @return list<non-empty-string> */
function stringList(array $row, string $key, string $context, bool $required = false): array
{
    $value = $row[$key] ?? null;
    if ($value === null && !$required) {
        return [];
    }
    if (!is_array($value)) {
        throw new RuntimeException($context . ' field "' . $key . '" must be an array.');
    }

    $result = [];
    foreach ($value as $item) {
        if (!is_string($item) || $item === '') {
            throw new RuntimeException($context . ' field "' . $key . '" must contain only non-empty strings.');
        }
        $result[] = $item;
    }

    if ($required && $result === []) {
        throw new RuntimeException($context . ' field "' . $key . '" must not be empty.');
    }

    return $result;
}

/** @return array<non-empty-string, ExperimentTask> */
function loadCorpus(string $path): array
{
    $json = decodeJsonFile($path);
    if (($json['schema'] ?? null) !== 'agent-map-polyglot-corpus@1') {
        throw new RuntimeException('Unsupported corpus schema in: ' . $path);
    }

    $rows = $json['tasks'] ?? null;
    if (!is_array($rows) || $rows === []) {
        throw new RuntimeException('Corpus must contain at least one task.');
    }

    $tasks = [];
    foreach ($rows as $offset => $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Corpus task #' . $offset . ' must be an object.');
        }
        $context = 'Corpus task #' . $offset;
        $id = requiredString($row, 'id', $context);
        if (isset($tasks[$id])) {
            throw new RuntimeException('Duplicate corpus task id: ' . $id);
        }

        $tasks[$id] = new ExperimentTask(
            id: $id,
            kind: requiredString($row, 'kind', $context),
            expectedPaths: stringList($row, 'expected_paths', $context, true),
            expectedRelations: stringList($row, 'expected_relations', $context),
        );
    }

    return $tasks;
}

function nullableNumber(array $row, string $key, string $context): int|float|null
{
    if (!array_key_exists($key, $row) || $row[$key] === null) {
        return null;
    }

    $value = $row[$key];
    if ((!is_int($value) && !is_float($value)) || $value < 0) {
        throw new RuntimeException($context . ' field "' . $key . '" must be a non-negative number or null.');
    }

    return $value;
}

function loadProviderResult(string $path): ProviderResult
{
    $json = decodeJsonFile($path);
    if (($json['schema'] ?? null) !== 'agent-map-polyglot-provider-result@1') {
        throw new RuntimeException('Unsupported provider-result schema in: ' . $path);
    }

    $rows = $json['tasks'] ?? null;
    if (!is_array($rows)) {
        throw new RuntimeException('Provider result requires a tasks array: ' . $path);
    }

    $tasks = [];
    $allowedStatuses = ['answered', 'not_found', 'unavailable', 'error'];
    foreach ($rows as $offset => $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Provider task #' . $offset . ' must be an object in: ' . $path);
        }
        $context = 'Provider task #' . $offset . ' in ' . $path;
        $id = requiredString($row, 'id', $context);
        if (isset($tasks[$id])) {
            throw new RuntimeException('Duplicate provider task id "' . $id . '" in: ' . $path);
        }

        $status = requiredString($row, 'status', $context);
        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException(
                $context . ' has unsupported status "' . $status . '".',
            );
        }

        $paths = stringList($row, 'paths', $context);
        $relations = stringList($row, 'relations', $context);
        if ($status !== 'answered' && ($paths !== [] || $relations !== [])) {
            throw new RuntimeException(
                $context . ' must not contain path or relation evidence when status is "' . $status . '".',
            );
        }

        $elapsedMs = nullableNumber($row, 'elapsed_ms', $context);
        $tasks[$id] = new ProviderTaskResult(
            id: $id,
            status: $status,
            paths: $paths,
            relations: $relations,
            elapsedMs: $elapsedMs === null ? null : (float) $elapsedMs,
        );
    }

    $metadata = $json['metadata'] ?? [];
    if (!is_array($metadata)) {
        throw new RuntimeException('Provider metadata must be an object in: ' . $path);
    }

    return new ProviderResult(
        provider: requiredString($json, 'provider', 'Provider result ' . $path),
        version: requiredString($json, 'version', 'Provider result ' . $path),
        tasks: $tasks,
        metadata: $metadata,
    );
}

/** @param array<non-empty-string, ExperimentTask> $corpus */
function scoreProvider(array $corpus, ProviderResult $provider): Score
{
    $corpusIds = array_keys($corpus);
    $providerIds = array_keys($provider->tasks);
    sort($corpusIds);
    sort($providerIds);
    if ($corpusIds !== $providerIds) {
        $missing = array_values(array_diff($corpusIds, $providerIds));
        $unknown = array_values(array_diff($providerIds, $corpusIds));
        throw new RuntimeException(
            'Provider ' . $provider->provider . ' task ids must exactly match corpus; missing=['
            . implode(', ', $missing) . '], unknown=[' . implode(', ', $unknown) . '].',
        );
    }

    $supported = 0;
    $unavailable = 0;
    $errors = 0;
    $hitAt1 = 0;
    $hitAt5 = 0;
    $falseAbsences = 0;
    $relationTruePositive = 0;
    $relationReturned = 0;
    $relationExpected = 0;
    $queryTimes = [];

    foreach ($corpus as $task) {
        $result = $provider->tasks[$task->id];

        if ($result->elapsedMs !== null) {
            $queryTimes[] = $result->elapsedMs;
        }

        if ($result->status === 'answered' || $result->status === 'not_found') {
            ++$supported;
        } elseif ($result->status === 'unavailable') {
            ++$unavailable;
        } elseif ($result->status === 'error') {
            ++$errors;
        }

        if ($result->status === 'not_found') {
            ++$falseAbsences;
        }

        if ($result->status === 'answered') {
            if ($result->paths !== [] && in_array($result->paths[0], $task->expectedPaths, true)) {
                ++$hitAt1;
            }
            if (array_intersect(array_slice($result->paths, 0, 5), $task->expectedPaths) !== []) {
                ++$hitAt5;
            }
        }

        if (
            $task->expectedRelations !== []
            && ($result->status === 'answered' || $result->status === 'not_found')
        ) {
            $expected = array_values(array_unique($task->expectedRelations));
            $relationExpected += count($expected);
            if ($result->status === 'answered') {
                $returned = array_values(array_unique($result->relations));
                $relationReturned += count($returned);
                $relationTruePositive += count(array_intersect($returned, $expected));
            }
        }
    }

    return new Score(
        provider: $provider->provider,
        version: $provider->version,
        tasks: count($corpus),
        supported: $supported,
        unavailable: $unavailable,
        errors: $errors,
        hitAt1: $hitAt1,
        hitAt5: $hitAt5,
        falseAbsences: $falseAbsences,
        relationTruePositive: $relationTruePositive,
        relationReturned: $relationReturned,
        relationExpected: $relationExpected,
        meanQueryMs: $queryTimes === [] ? null : array_sum($queryTimes) / count($queryTimes),
        coldIndexMs: nullableNumber($provider->metadata, 'cold_index_ms', 'Provider metadata'),
        warmIndexMs: nullableNumber($provider->metadata, 'warm_index_ms', 'Provider metadata'),
        indexBytes: nullableNumber($provider->metadata, 'index_bytes', 'Provider metadata'),
    );
}

function percent(?float $value): string
{
    return $value === null ? 'n/a' : number_format($value * 100, 1) . '%';
}

function numberOrNa(int|float|null $value): string
{
    return $value === null ? 'n/a' : number_format((float) $value, 1, '.', '');
}

/** @param list<Score> $scores */
function printScores(array $scores): void
{
    $header = [
        'provider',
        'coverage',
        'unavailable',
        'errors',
        'hit@1',
        'hit@5',
        'false-absence',
        'rel-precision',
        'rel-recall',
        'query-ms',
        'cold-index-ms',
        'warm-index-ms',
        'index-bytes',
    ];
    echo implode("\t", $header), PHP_EOL;

    foreach ($scores as $score) {
        echo implode("\t", [
            $score->provider . '@' . $score->version,
            percent($score->coverageRate()),
            (string) $score->unavailable,
            (string) $score->errors,
            percent($score->hitAt1Rate()),
            percent($score->hitAt5Rate()),
            percent($score->falseAbsenceRate()),
            percent($score->relationPrecision()),
            percent($score->relationRecall()),
            numberOrNa($score->meanQueryMs),
            numberOrNa($score->coldIndexMs),
            numberOrNa($score->warmIndexMs),
            numberOrNa($score->indexBytes),
        ]), PHP_EOL;
    }
}

/** @return array<string, string> */
function options(array $argv): array
{
    $result = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Expected --name=value argument, got: ' . $argument);
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || $value === '') {
            throw new InvalidArgumentException('Expected non-empty --name=value argument, got: ' . $argument);
        }
        $result[$name] = $value;
    }

    return $result;
}

try {
    $options = options($argv);
    $corpusPath = $options['corpus'] ?? null;
    $resultsOption = $options['results'] ?? null;
    if ($corpusPath === null || $resultsOption === null) {
        throw new InvalidArgumentException('Usage: --corpus=<corpus.json> --results=<provider-a.json,provider-b.json>');
    }

    $corpus = loadCorpus($corpusPath);
    $resultPaths = array_values(array_filter(
        array_map('trim', explode(',', $resultsOption)),
        static fn (string $path): bool => $path !== '',
    ));
    if ($resultPaths === []) {
        throw new InvalidArgumentException('--results must contain at least one file.');
    }

    $scores = [];
    foreach ($resultPaths as $resultPath) {
        $scores[] = scoreProvider($corpus, loadProviderResult($resultPath));
    }

    usort(
        $scores,
        static fn (Score $left, Score $right): int => $left->falseAbsenceRate() <=> $right->falseAbsenceRate()
            ?: $right->hitAt5Rate() <=> $left->hitAt5Rate()
            ?: $right->coverageRate() <=> $left->coverageRate()
            ?: $right->hitAt1Rate() <=> $left->hitAt1Rate()
            ?: $left->provider <=> $right->provider,
    );

    printScores($scores);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
