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
    ) {
    }

    /**
     * @param list<string> $tokens
     */
    public static function parse(array $tokens): self
    {
        $command = array_shift($tokens);
        if ($command === null || $command === '') {
            throw new InvalidArgumentException('Missing command. Run agent-map help.');
        }

        if (in_array($command, ['-h', '--help'], true)) {
            $command = 'help';
        }

        if (!in_array($command, ['help', 'build', 'query', 'file', 'stale', 'summary', 'changed', 'related', 'stats'], true)) {
            throw new InvalidArgumentException('Unknown command: ' . $command);
        }

        $values = [
            'root' => getcwd() ?: '.',
            'paths' => '.',
            'out' => '.agent-map/php-symbols.json',
            'index' => '.agent-map/php-symbols.json',
            'format' => 'text',
            'limit' => '20',
            'symbol-limit' => '10',
            'method-limit' => '10',
            'base' => 'main',
        ];
        $excludes = [];
        $argument = null;
        $help = false;

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
        }

        if (in_array($command, ['query', 'file', 'related'], true) && !$help && ($argument === null || $argument === '')) {
            throw new InvalidArgumentException('Missing argument for command: ' . $command);
        }

        if (!in_array($values['format'], ['text', 'json', 'markdown', 'toon'], true)) {
            throw new InvalidArgumentException('Unknown format: ' . $values['format']);
        }

        $limit = filter_var($values['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($limit)) {
            throw new InvalidArgumentException('Invalid limit: ' . $values['limit']);
        }

        $methodLimit = filter_var($values['method-limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($methodLimit)) {
            throw new InvalidArgumentException('Invalid method-limit: ' . $values['method-limit']);
        }

        $symbolLimit = filter_var($values['symbol-limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($symbolLimit)) {
            throw new InvalidArgumentException('Invalid symbol-limit: ' . $values['symbol-limit']);
        }

        return new self(
            $command,
            $argument,
            $values['root'],
            self::splitPaths($values['paths']),
            $values['out'],
            $values['index'],
            $values['format'],
            $limit,
            $symbolLimit,
            $methodLimit,
            $values['base'],
            $excludes,
            $help,
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

    /**
     * @return list<string>
     */
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
