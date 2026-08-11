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
use voku\AgentMap\Temporal\CoChangeReport;
use voku\AgentMap\Temporal\EntityHistoryReport;
use voku\AgentMap\Temporal\EntityObservationBuilder;
use voku\AgentMap\Temporal\GitChangeHistory;
use voku\AgentMap\Temporal\GitRevisionInspector;
use voku\AgentMap\Temporal\StructuralDiffer;
use voku\AgentMap\Temporal\TemporalClaim;
use voku\AgentMap\Temporal\TemporalClaimAnalyzer;
use voku\AgentMap\Temporal\TemporalHistoryStore;

final readonly class TemporalCliApplication
{
    private const DEFAULT_INDEX = '.agent-map/php-symbols.json';
    private const DEFAULT_DATABASE = '.agent-map/history.sqlite';

    /** @var list<string> */
    private const COUPLING_OPTIONS = ['index', 'root', 'commits', 'top', 'min-cochanges', 'max-files-per-commit'];

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
  history claims     Derive explicit heuristic claims from temporal evidence
  history observe    Persist compact evidence for one clean Git revision
  history show       Inspect one entity across recorded revisions

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
                'claims' => $this->claims(array_slice($argv, 3)),
                'observe' => $this->observe(array_slice($argv, 3)),
                'show' => $this->show(array_slice($argv, 3)),
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

        echo $this->render(['type' => 'history_diff', ...$report->toArray($limit)], $format, $this->textRenderer->diff($report, $limit));
        return 0;
    }

    /** @param list<string> $tokens */
    private function coupling(array $tokens): int
    {
        $parsed = $this->parse($tokens, [...self::COUPLING_OPTIONS, 'format']);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }
        $this->rejectArguments($parsed['arguments'], 'history coupling');
        $report = $this->coChangeReport($parsed['options']);

        echo $this->render(
            ['type' => 'history_coupling', ...$report->toArray()],
            $this->format($parsed['options']['format'] ?? 'text'),
            $this->textRenderer->coupling($report),
        );
        return 0;
    }

    /** @param list<string> $tokens */
    private function claims(array $tokens): int
    {
        $parsed = $this->parse($tokens, [...self::COUPLING_OPTIONS, 'min-ratio', 'format']);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }
        $this->rejectArguments($parsed['arguments'], 'history claims');
        $minimumRatio = $this->ratio('min-ratio', $parsed['options']['min-ratio'] ?? '0.6');
        $claims = (new TemporalClaimAnalyzer())->hiddenCoupling($this->coChangeReport($parsed['options']), $minimumRatio);
        $payload = [
            'type' => 'history_claims',
            'claim_kind' => 'hidden_temporal_coupling',
            'heuristic' => true,
            'minimum_smaller_side_ratio' => $minimumRatio,
            'claim_count' => count($claims),
            'claims' => array_map(static fn (TemporalClaim $claim): array => $claim->toArray(), $claims),
        ];

        echo $this->render(
            $payload,
            $this->format($parsed['options']['format'] ?? 'text'),
            $this->textRenderer->claims($claims, $minimumRatio),
        );
        return 0;
    }

    /** @param list<string> $tokens */
    private function observe(array $tokens): int
    {
        $parsed = $this->parse($tokens, ['index', 'database', 'format']);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }
        $this->rejectArguments($parsed['arguments'], 'history observe');

        $map = $this->loadFresh($parsed['options']['index'] ?? self::DEFAULT_INDEX);
        $revision = (new GitRevisionInspector())->current($map->root);
        $observations = (new EntityObservationBuilder())->build($map);
        $database = $this->absolute($map->root, $parsed['options']['database'] ?? self::DEFAULT_DATABASE);
        $store = new TemporalHistoryStore($database);
        $snapshotId = $store->record($map, $revision, $observations);
        $payload = [
            'type' => 'history_observe',
            'snapshot_id' => $snapshotId,
            'revision' => $revision->commit,
            'committed_at' => $revision->committedAt,
            'map_digest' => $map->mapDigest(),
            'observation_count' => count($observations),
            'snapshot_count' => $store->snapshotCount(),
            'database' => $database,
        ];
        $text = sprintf(
            "Recorded temporal snapshot %d for %s with %d entity observation(s).\n",
            $snapshotId,
            substr($revision->commit, 0, 12),
            count($observations),
        );

        echo $this->render($payload, $this->format($parsed['options']['format'] ?? 'text'), $text);
        return 0;
    }

    /** @param list<string> $tokens */
    private function show(array $tokens): int
    {
        $parsed = $this->parse($tokens, ['database', 'format']);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }
        if (count($parsed['arguments']) !== 1) {
            throw new InvalidArgumentException('history show requires exactly one entity id.');
        }

        $entityId = $parsed['arguments'][0];
        $database = $this->absolute(getcwd() ?: '.', $parsed['options']['database'] ?? self::DEFAULT_DATABASE);
        if (!is_file($database)) {
            throw new RuntimeException('Temporal history database not found: ' . $database . '. Run agent-map history observe first.');
        }
        $store = new TemporalHistoryStore($database);
        $report = new EntityHistoryReport($entityId, $store->entityHistory($entityId), $store->latestSnapshot());

        echo $this->render(
            ['type' => 'history_entity', ...$report->toArray()],
            $this->format($parsed['options']['format'] ?? 'text'),
            $this->textRenderer->entityHistory($report),
        );
        return 0;
    }

    /** @param array<string, string> $options */
    private function coChangeReport(array $options): CoChangeReport
    {
        $map = $this->loadFresh($options['index'] ?? self::DEFAULT_INDEX);
        $history = (new GitChangeHistory())->commits(
            $options['root'] ?? $map->root,
            $this->positiveInt('commits', $options['commits'] ?? '100'),
        );

        return (new CoChangeAnalyzer())->analyze(
            $map,
            $history,
            $this->positiveInt('min-cochanges', $options['min-cochanges'] ?? '2'),
            $this->positiveInt('top', $options['top'] ?? '20'),
            $this->positiveInt('max-files-per-commit', $options['max-files-per-commit'] ?? '100'),
        );
    }

    private function loadFresh(string $path): AgentMapIndex
    {
        $map = (new IndexReader())->read($path);
        $stale = $map->staleEntries();
        if ($stale !== []) {
            throw new RuntimeException(sprintf(
                'Agent map is stale for %d file(s). Run agent-map refresh before temporal analysis.',
                count($stale),
            ));
        }

        return $map;
    }

    private function absolute(string $root, string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1)) {
            return $path;
        }

        return rtrim(str_replace('\\', '/', $root), '/') . '/' . ltrim($path, '/');
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
                             [--max-files-per-commit=100] [--top=20] [--format=...]
  agent-map history claims [same coupling options] [--min-ratio=0.6] [--format=...]
  agent-map history observe [--index=MAP] [--database=.agent-map/history.sqlite] [--format=...]
  agent-map history show ENTITY [--database=.agent-map/history.sqlite] [--format=...]

`history diff` compares canonical map structure and ignores line-number-only relation movement.
`history coupling` exposes bounded Git co-change beside current semantic/path coupling.
`history claims` currently emits only `hidden_temporal_coupling`: repeated strong co-change for a pair
with no current semantic graph edge. Claims are explicitly heuristic and carry their Git revisions.
`history observe` records compact entity metrics only for a clean Git revision, keeping history
rebuildable from Git plus canonical maps. It stores no source text, embeddings, or raw relation events.
`history show` is read-only and reveals lifecycle, signature/path/region variants, and graph metric deltas.
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

    private function ratio(string $name, string $value): float
    {
        if (!is_numeric($value) || (float) $value < 0.0 || (float) $value > 1.0) {
            throw new InvalidArgumentException('--' . $name . ' must be a number between 0 and 1.');
        }

        return (float) $value;
    }

    private function format(string $value): string
    {
        if (!in_array($value, ['text', 'json', 'toon', 'markdown'], true)) {
            throw new InvalidArgumentException('--format must be text, json, toon, or markdown.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
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
