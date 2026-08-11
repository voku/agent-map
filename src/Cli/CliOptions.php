<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use InvalidArgumentException;

final readonly class CliOptions
{
    /**
     * @param list<string> $paths
     * @param list<string> $scanPaths
     * @param list<string> $excludes
     */
    public function __construct(
        public string $command,
        public ?string $argument,
        public string $root,
        public array $paths,
        public array $scanPaths,
        public string $out,
        public string $index,
        public string $database,
        public string $cases,
        public string $format,
        public int $limit,
        public int $symbolLimit,
        public int $methodLimit,
        public string $base,
        public array $excludes,
        public bool $help,
        public bool $merge,
        public bool $semantic,
        public ?string $phpStanConfig,
        public ?string $phpStanMemoryLimit,
        public int $contextBudget,
        public int $maxFiles,
        public int $maxCallers,
        public int $maxCallees,
        public int $maxTests,
        public int $maxTypeDefinitions,
    ) {
    }

    /** @param list<string> $tokens */
    public static function parse(array $tokens): self
    {
        $command = array_shift($tokens);
        if ($command === null || $command === '') {
            throw new InvalidArgumentException('Missing command. Run agent-map help.');
        }
        if (in_array($command, ['-h', '--help'], true)) {
            $command = 'help';
        }
        $commands = ['help', 'build', 'refresh', 'query', 'file', 'stale', 'summary', 'changed', 'related', 'stats', 'scope', 'callers', 'callees', 'context', 'search-index', 'search'];
        if (!in_array($command, $commands, true)) {
            throw new InvalidArgumentException('Unknown command: ' . $command);
        }

        $values = [
            'root' => getcwd() ?: '.',
            'paths' => '.',
            'scan' => '',
            'out' => '.agent-loop/map/php-symbols.json',
            'index' => '.agent-loop/map/php-symbols.json',
            'database' => '.agent-loop/map/search.sqlite',
            'cases' => 'tests/fixtures/search-cases.json',
            'format' => in_array($command, ['build', 'refresh'], true) ? 'json' : 'text',
            'limit' => $command === 'scope' ? '10' : '20',
            'symbol-limit' => '10',
            'method-limit' => '10',
            'base' => 'main',
            'phpstan-config' => '',
            'phpstan-memory-limit' => '',
            'context-budget' => '60000',
            'max-files' => '20',
            'max-callers' => '10',
            'max-callees' => '10',
            'max-tests' => '10',
            'max-type-definitions' => '10',
        ];
        $excludes = [];
        $argument = null;
        $help = false;
        $merge = false;
        $semantic = false;
        $formatProvided = false;
        $outProvided = false;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--help' || $token === '-h') {
                $help = true;
                continue;
            }
            if ($token === '--merge') {
                $merge = true;
                continue;
            }
            if ($token === '--semantic') {
                $semantic = true;
                continue;
            }
            if (!str_starts_with($token, '--')) {
                if ($argument !== null) {
                    throw new InvalidArgumentException('Unexpected argument: ' . $token);
                }
                $argument = $token;
                continue;
            }
            [$name, $value, $consumedNext] = self::readOption($token, $tokens, $i);
            if ($consumedNext) {
                ++$i;
            }
            if ($name === 'exclude') {
                $excludes[] = $value;
                continue;
            }
            if (!array_key_exists($name, $values)) {
                throw new InvalidArgumentException('Unknown option: --' . $name);
            }
            $values[$name] = $value;
            $formatProvided = $formatProvided || $name === 'format';
            $outProvided = $outProvided || $name === 'out';
        }

        if (in_array($command, ['query', 'file', 'related', 'scope', 'callers', 'callees', 'context', 'search'], true) && !$help && ($argument === null || $argument === '')) {
            throw new InvalidArgumentException('Missing argument for command: ' . $command);
        }
        if ($command === 'build' || $command === 'refresh') {
            if (!$formatProvided && str_ends_with(strtolower($values['out']), '.toon')) {
                $values['format'] = 'toon';
            }
            if (!$outProvided && $values['format'] === 'toon') {
                $values['out'] = '.agent-loop/map/php-symbols.toon';
            }
        }

        $allowedFormats = in_array($command, ['build', 'refresh'], true) ? ['json', 'toon'] : ['text', 'json', 'markdown', 'toon'];
        if (!in_array($values['format'], $allowedFormats, true)) {
            throw new InvalidArgumentException('Unknown format for ' . $command . ': ' . $values['format']);
        }

        return new self(
            command: $command,
            argument: $argument,
            root: $values['root'],
            paths: self::splitPaths($values['paths']),
            scanPaths: self::splitList($values['scan']),
            out: $values['out'],
            index: $values['index'],
            database: $values['database'],
            cases: $values['cases'],
            format: $values['format'],
            limit: self::positiveInt('limit', $values['limit'], 1),
            symbolLimit: self::positiveInt('symbol-limit', $values['symbol-limit'], 1),
            methodLimit: self::positiveInt('method-limit', $values['method-limit'], 0),
            base: $values['base'],
            excludes: $excludes,
            help: $help,
            merge: $merge,
            semantic: $semantic,
            phpStanConfig: $values['phpstan-config'] !== '' ? $values['phpstan-config'] : null,
            phpStanMemoryLimit: self::memoryLimit($values['phpstan-memory-limit']),
            contextBudget: self::positiveInt('context-budget', $values['context-budget'], 1),
            maxFiles: self::positiveInt('max-files', $values['max-files'], 1),
            maxCallers: self::positiveInt('max-callers', $values['max-callers'], 0),
            maxCallees: self::positiveInt('max-callees', $values['max-callees'], 0),
            maxTests: self::positiveInt('max-tests', $values['max-tests'], 0),
            maxTypeDefinitions: self::positiveInt('max-type-definitions', $values['max-type-definitions'], 0),
        );
    }

    /**
     * @param list<string> $tokens
     * @return array{0: string, 1: string, 2: bool}
     */
    private static function readOption(string $token, array $tokens, int $index): array
    {
        $raw = substr($token, 2);
        if (str_contains($raw, '=')) {
            [$name, $value] = explode('=', $raw, 2);
            return [$name, $value, false];
        }
        $value = $tokens[$index + 1] ?? null;
        if (!is_string($value) || str_starts_with($value, '--')) {
            throw new InvalidArgumentException('Missing value for option: --' . $raw);
        }
        return [$raw, $value, true];
    }

    private static function positiveInt(string $name, string $value, int $minimum): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('Invalid ' . $name . ': ' . $value);
        }

        return $integer;
    }

    private static function memoryLimit(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A[1-9][0-9]*(?:[KMG])?\z/i', $value) !== 1) {
            throw new InvalidArgumentException('Invalid phpstan-memory-limit: ' . $value);
        }

        return strtoupper($value);
    }

    /** @return list<string> */
    private static function splitList(string $paths): array
    {
        $result = [];
        foreach (explode(',', $paths) as $path) {
            $path = trim($path);
            if ($path !== '') {
                $result[] = $path;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function splitPaths(string $paths): array
    {
        $result = self::splitList($paths);

        return $result === [] ? ['.'] : $result;
    }
}
