<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentMap\Discovery\ArchitectureDiscovery;
use voku\AgentMap\Discovery\ArchitectureImpactAnalyzer;
use voku\AgentMap\Discovery\ArchitectureMapReport;
use voku\AgentMap\Discovery\ArchitectureRegion;
use voku\AgentMap\Discovery\GraphMetric;
use voku\AgentMap\Discovery\GraphRanker;
use voku\AgentMap\Discovery\RankedNode;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

final readonly class DiscoveryCliApplication
{
    private const DEFAULT_INDEX = '.agent-loop/map/php-symbols.json';

    public function __construct(
        private DiscoveryTextRenderer $textRenderer = new DiscoveryTextRenderer(),
    ) {
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        $command = $argv[1] ?? null;
        if (in_array($command, ['discover', 'rank', 'impact'], true)) {
            return true;
        }

        return $command === 'help' && in_array($argv[2] ?? null, ['discover', 'rank', 'impact'], true);
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Architecture discovery:
  discover   Infer PHP architecture regions and evidence-backed starting points
  rank       Rank nodes by one-hop graph importance
  impact     Trace bounded reverse impact for a method

Run `agent-map help <command>` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? '';
            if ($command === 'help') {
                echo $this->help($argv[2] ?? '');
                return 0;
            }

            return match ($command) {
                'discover' => $this->discover(array_slice($argv, 2)),
                'rank' => $this->rank(array_slice($argv, 2)),
                'impact' => $this->impact(array_slice($argv, 2)),
                default => 1,
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function discover(array $tokens): int
    {
        $parsed = $this->parse($tokens, ['index', 'limit', 'format', 'region']);
        if ($parsed['help']) {
            echo $this->help('discover');
            return 0;
        }
        $this->rejectArguments($parsed['arguments'], 'discover');

        $limit = $this->positiveInt('limit', $parsed['options']['limit'] ?? '10');
        $format = $this->format($parsed['options']['format'] ?? 'text');
        $map = $this->loadFresh($parsed['options']['index'] ?? self::DEFAULT_INDEX);
        $report = (new ArchitectureDiscovery())->discover($map, $limit);
        $architecture = $report->architecture;
        if (!$architecture instanceof ArchitectureMapReport) {
            throw new RuntimeException('Architecture discovery did not return an architecture map.');
        }
        $regionQuery = $parsed['options']['region'] ?? null;

        if ($regionQuery !== null) {
            $region = $architecture->resolveRegion($regionQuery);
            $payload = [
                'type' => 'discover_region',
                'map_digest' => $report->mapDigest,
                'path' => $this->architecturePathPayload($architecture, $region),
                'region' => $region->toArray(),
            ];
            echo $this->render(
                $payload,
                $format,
                $this->textRenderer->region($region, $architecture, $limit),
            );
            return 0;
        }

        echo $this->render(
            ['type' => 'discover', ...$report->toArray()],
            $format,
            $this->textRenderer->discovery($report),
        );
        return 0;
    }

    /** @param list<string> $tokens */
    private function rank(array $tokens): int
    {
        $parsed = $this->parse($tokens, ['index', 'by', 'kind', 'top', 'format']);
        if ($parsed['help']) {
            echo $this->help('rank');
            return 0;
        }
        $this->rejectArguments($parsed['arguments'], 'rank');

        $metricName = $parsed['options']['by'] ?? 'dependents';
        $metric = GraphMetric::tryFrom($metricName);
        if ($metric === null) {
            throw new InvalidArgumentException('Unknown graph metric: ' . $metricName);
        }

        $top = $this->positiveInt('top', $parsed['options']['top'] ?? '10');
        $format = $this->format($parsed['options']['format'] ?? 'text');
        $kind = $parsed['options']['kind'] ?? null;
        $map = $this->loadFresh($parsed['options']['index'] ?? self::DEFAULT_INDEX);
        $ranked = (new GraphRanker())->rank($map, $metric, $kind, $top);
        $payload = [
            'type' => 'rank',
            'metric' => $metric->value,
            'kind' => $kind,
            'results' => array_map(static fn (RankedNode $row): array => $row->toArray(), $ranked),
        ];

        echo $this->render($payload, $format, $this->textRenderer->rank($metric, $ranked));
        return 0;
    }

    /** @param list<string> $tokens */
    private function impact(array $tokens): int
    {
        $parsed = $this->parse($tokens, ['index', 'depth', 'max-nodes', 'format']);
        if ($parsed['help']) {
            echo $this->help('impact');
            return 0;
        }
        if (count($parsed['arguments']) !== 1) {
            throw new InvalidArgumentException('impact requires exactly one Class::method target.');
        }

        $depth = $this->positiveInt('depth', $parsed['options']['depth'] ?? '2');
        $maximumNodes = $this->positiveInt('max-nodes', $parsed['options']['max-nodes'] ?? '100');
        $format = $this->format($parsed['options']['format'] ?? 'text');
        $map = $this->loadFresh($parsed['options']['index'] ?? self::DEFAULT_INDEX);
        $report = (new ArchitectureImpactAnalyzer())->forMethod(
            $map,
            $parsed['arguments'][0],
            $depth,
            $maximumNodes,
        );

        echo $this->render(
            ['type' => 'impact', ...$report->toArray()],
            $format,
            $this->textRenderer->impact($report),
        );
        return 0;
    }

    private function loadFresh(string $path): AgentMapIndex
    {
        $map = (new IndexReader())->read($path);
        $stale = $map->staleEntries();
        if ($stale !== []) {
            throw new RuntimeException(sprintf(
                'Agent map is stale for %d file(s). Run agent-map refresh before architecture discovery.',
                count($stale),
            ));
        }

        return $map;
    }

    /** @return list<array{id: string, label: string, kind: string, level: int}> */
    private function architecturePathPayload(ArchitectureMapReport $architecture, ArchitectureRegion $region): array
    {
        return array_map(
            static fn (ArchitectureRegion $item): array => [
                'id' => $item->id,
                'label' => $item->label,
                'kind' => $item->kind,
                'level' => $item->level,
            ],
            array_reverse($architecture->pathForRegion($region)),
        );
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

    /** @param list<string> $arguments */
    private function rejectArguments(array $arguments, string $command): void
    {
        if ($arguments !== []) {
            throw new InvalidArgumentException($command . ' does not accept positional arguments.');
        }
    }

    private function positiveInt(string $name, string $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('Invalid ' . $name . ': ' . $value);
        }

        return $integer;
    }

    private function format(string $format): string
    {
        if (!in_array($format, ['text', 'json', 'markdown', 'toon'], true)) {
            throw new InvalidArgumentException('Unknown output format: ' . $format);
        }

        return $format;
    }

    /** @param array<string, mixed> $payload */
    private function render(array $payload, string $format, string $text): string
    {
        return match ($format) {
            'json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'toon' => Toon::encode($payload) . "\n",
            'markdown' => "## Agent Map architecture discovery\n\n```text\n" . rtrim($text) . "\n```\n",
            default => $text,
        };
    }

    private function help(string $command): string
    {
        return match ($command) {
            'discover' => <<<'TEXT'
Usage: agent-map discover [--index PATH] [--limit N] [--region LABEL|ID] [--format text|json|markdown|toon]

Infer deterministic PHP architecture regions first, then report entrypoint candidates,
call hubs, orchestrators, type hubs, namespace/directory/file coupling and relation quality.
Use --region after the first discovery pass to inspect a region without guessing file names.
Region evidence exposes boundaries and structural agreement instead of an opaque confidence score.

TEXT,
            'rank' => <<<'TEXT'
Usage: agent-map rank [--by METRIC] [--kind KIND] [--top N] [--index PATH] [--format FORMAT]

Metrics: dependents, callers, dependencies, callees, members.
Scores are unique one-hop graph neighbours; uncertainty is reported separately.

TEXT,
            'impact' => <<<'TEXT'
Usage: agent-map impact Class::method [--depth N] [--max-nodes N] [--index PATH] [--format FORMAT]

Trace bounded reverse dependency impact while preserving relation evidence and uncertainty.
Impacted nodes are also grouped by the inferred PHP architecture map.

TEXT,
            default => $this->helpOverview(),
        };
    }
}
