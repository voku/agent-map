<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use InvalidArgumentException;

final readonly class CliOptions
{
    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    public function __construct(
        public string $command,
        public ?string $argument,
        public string $root,
        public array $paths,
        public string $out,
        public string $index,
        public string $format,
        public int $limit,
        public int $symbolLimit,
        public int $methodLimit,
        public string $base,
        public array $excludes,
        public bool $help,
        public ?string $phpStanConfig,
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
        $commands = ['help', 'build', 'query', 'file', 'stale', 'summary', 'changed', 'related', 'stats', 'callers', 'callees', 'context'];
        if (!in_array($command, $commands, true)) {
            throw new InvalidArgumentException('Unknown command: ' . $command);
        }

        $values = [
            'root' => getcwd() ?: '.',
            'paths' => '.',
            'out' => '.agent-map/php-symbols.json',
            'index' => '.agent-map/php-symbols.json',
            'format' => $command === 'build' ? 'json' : 'text',
            'limit' => '20',
            'symbol-limit' => '10',
            'method-limit' => '10',
            'base' => 'main',
            'phpstan-config' => '',
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
        $formatProvided = false;
        $outProvided = false;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--help' || $token === '-h') {
                $help = true;
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

        if (in_array($command, ['query', 'file', 'related', 'callers', 'callees', 'context'], true) && !$help && ($argument === null || $argument === '')) {
            throw new InvalidArgumentException('Missing argument for command: ' . $command);
        }
        if ($command === 'build') {
            if (!$formatProvided && str_ends_with(strtolower($values['out']), '.toon')) {
                $values['format'] = 'toon';
            }
            if (!$outProvided && $values['format'] === 'toon') {
                $values['out'] = '.agent-map/php-symbols.toon';
            }
        }

        $allowedFormats = $command === 'build' ? ['json', 'toon'] : ['text', 'json', 'markdown', 'toon'];
        if (!in_array($values['format'], $allowedFormats, true)) {
            throw new InvalidArgumentException('Unknown format for ' . $command . ': ' . $values['format']);
        }

        return new self(
            command: $command,
            argument: $argument,
            root: $values['root'],
            paths: self::splitPaths($values['paths']),
            out: $values['out'],
            index: $values['index'],
            format: $values['format'],
            limit: self::positiveInt('limit', $values['limit'], 1),
            symbolLimit: self::positiveInt('symbol-limit', $values['symbol-limit'], 1),
            methodLimit: self::positiveInt('method-limit', $values['method-limit'], 0),
            base: $values['base'],
            excludes: $excludes,
            help: $help,
            phpStanConfig: $values['phpstan-config'] !== '' ? $values['phpstan-config'] : null,
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

    /** @return list<string> */
    private static function splitPaths(string $paths): array
    {
        $result = [];
        foreach (explode(',', $paths) as $path) {
            $path = trim($path);
            if ($path !== '') {
                $result[] = $path;
            }
        }
        return $result === [] ? ['.'] : $result;
    }
}
