<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Temporal\CoChangeAnalyzer;
use voku\AgentMap\Temporal\GitChangeHistory;
use voku\AgentMap\Temporal\StructuralDiffer;

final readonly class TemporalCliApplication
{
    private const DEFAULT_INDEX = '.agent-map/php-symbols.json';

    public function __construct(
        private TemporalTextRenderer $textRenderer = new TemporalTextRenderer(),
    ) {
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        $command = $argv[1] ?? null;
        if ($command === 'history') {
            return true;
        }

        return $command === 'help' && ($argv[2] ?? null) === 'history';
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Temporal evolution:
  history diff       Compare two canonical map snapshots
  history coupling   Compare Git co-change evidence with current static coupling

Run `agent-map help history` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            if (($argv[1] ?? '') === 'help') {
                echo $this->help();
                return 0;
            }

            $subcommand = $argv[2] ?? 'help';
            return match ($subcommand) {
                'help', '-h', '--help' => $this->printHelp(),
                'diff' => $this->diff(array_slice($argv, 3)),
                'coupling' => $this->coupling(array_slice($argv, 3)),
                default => throw new InvalidArgumentException('Unknown history command: ' . $subcommand),
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function diff(array $tokens): int
    {
        $parsed = $this->parse($tokens, ['before', 'after', 'limit', 'format']);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }
        $this->rejectArguments($parsed['arguments'], 'history diff');

        $beforePath = $parsed['options']['before'] ?? null;
        $afterPath = $parsed['options']['after'] ?? null;
        if ($beforePath === null || $afterPath === null) {
            throw new InvalidArgumentException('history diff requires --before=MAP and --after=MAP.');
        }

        $limit = $this->positiveInt('limit', $parsed['options']['limit'] ?? '100');
        $format = $this->format($parsed['options']['format'] ?? 'text');
        $reader = new IndexReader();
        $report = (new StructuralDiffer())->diff($reader->read($beforePath), $reader->read($afterPath));
        $payload = ['type' => 'history_diff', ...$report->toArray($limit)];

        echo $this->render($payload, $format, $this->textRenderer->diff($report, $limit));
        return 0;
    }

    /** @param list<string> $tokens */
    private function coupling(array $tokens): int
    {
        $parsed = $this->parse($tokens, ['index', 'root', 'commits', 'top', 'min-cochanges', 'max-files-per-commit', 'format']);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }
        $this->rejectArguments($parsed['arguments'], 'history coupling');

        $map = $this->loadFresh($parsed['options']['index'] ?? self::DEFAULT_INDEX);
        $root = $parsed['options']['root'] ?? $map->root;
        $commits = $this->positiveInt('commits', $parsed['options']['commits'] ?? '100');
        $top = $this->positiveInt('top', $parsed['options']['top'] ?? '20');
        $minimumCoChanges = $this->positiveInt('min-cochanges', $parsed['options']['min-cochanges'] ?? '2');
        $maximumFiles = $this->positiveInt('max-files-per-commit', $parsed['options']['max-files-per-commit'] ?? '100');
        $format = $this->format($parsed['options']['format'] ?? 'text');

        $history = (new GitChangeHistory())->commits($root, $commits);
        $report = (new CoChangeAnalyzer())->analyze($map, $history, $minimumCoChanges, $top, $maximumFiles);
        $payload = ['type' => 'history_coupling', ...$report->toArray()];

        echo $this->render($payload, $format, $this->textRenderer->coupling($report));
        return 0;
    }

    private function loadFresh(string $path): AgentMapIndex
    {
        $map = (new IndexReader())->read($path);
        $stale = $map->staleEntries();
        if ($stale !== []) {
            throw new RuntimeException(sprintf(
                'Agent map is stale for %d file(s). Run agent-map refresh before temporal coupling analysis.',
                count($stale),
            ));
        }

        return $map;
    }

    /** @param list<string> $arguments */
    private function rejectArguments(array $arguments, string $command): void
    {
        if ($arguments !== []) {
            throw new InvalidArgumentException($command . ' accepts options only.');
        }
    }

    private function printHelp(): int
    {
        echo $this->help();
        return 0;
    }

    private function help(): string
    {
        return <<<'TEXT'
Temporal evolution:
  agent-map history diff --before=MAP --after=MAP [--limit=100] [--format=text|json|toon|markdown]
  agent-map history coupling [--index=MAP] [--root=PATH] [--commits=100] [--min-cochanges=2]
                             [--max-files-per-commit=100] [--top=20] [--format=text|json|toon|markdown]

`history diff` compares canonical map structure. It reports file, symbol, method, and semantic-relation
changes while deliberately ignoring line-number-only movement in relations.

`history coupling` reads bounded Git history, filters it to files in the current map, skips bulk commits
that would turn repository-wide formatting into artificial coupling, and exposes co-change ratios beside
current semantic/path coupling. Neither command claims why a change happened; rename, hotspot, and smell
interpretations must remain evidence-backed claims rather than map facts.
TEXT;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $allowedOptions
     * @return array{arguments: list<string>, options: array<string, string>, help: bool}
     */
    private function parse(array $tokens, array $allowedOptions): array
    {
        $arguments = [];
        $options = [];
        $help = false;

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '-h' || $token === '--help') {
                $help = true;
                continue;
            }
            if (!str_starts_with($token, '--')) {
                $arguments[] = $token;
                continue;
            }

            $raw = substr($token, 2);
            if (str_contains($raw, '=')) {
                [$name, $value] = explode('=', $raw, 2);
            } else {
                $name = $raw;
                $value = $tokens[$index + 1] ?? null;
                if (!is_string($value) || str_starts_with($value, '--')) {
                    throw new InvalidArgumentException('Missing value for option: --' . $name);
                }
                ++$index;
            }

            if (!in_array($name, $allowedOptions, true)) {
                throw new InvalidArgumentException('Unknown option: --' . $name);
            }
            if ($value === '') {
                throw new InvalidArgumentException('Empty value for option: --' . $name);
            }
            $options[$name] = $value;
        }

        return ['arguments' => $arguments, 'options' => $options, 'help' => $help];
    }

    private function positiveInt(string $name, string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidArgumentException('--' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private function format(string $value): string
    {
        if (!in_array($value, ['text', 'json', 'toon', 'markdown'], true)) {
            throw new InvalidArgumentException('--format must be text, json, toon, or markdown.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render(array $payload, string $format, string $text): string
    {
        return match ($format) {
            'json' => $this->json($payload),
            'toon' => Toon::encode($payload) . "\n",
            'markdown' => "## Temporal evidence\n\n```text\n" . rtrim($text) . "\n```\n",
            default => $text,
        };
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode temporal evidence JSON.');
        }

        return $json . "\n";
    }
}
