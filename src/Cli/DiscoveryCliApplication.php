<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentMap\Discovery\ArchitectureDiscovery;
use voku\AgentMap\Discovery\ArchitectureMapReport;
use voku\AgentMap\Discovery\ArchitectureRegion;
use voku\AgentMap\Discovery\GraphMetric;
use voku\AgentMap\Discovery\GraphRanker;
use voku\AgentMap\Discovery\ImpactAnalyzer;
use voku\AgentMap\Discovery\ImpactNode;
use voku\AgentMap\Discovery\ImpactRegionBucket;
use voku\AgentMap\Discovery\ImpactReport;
use voku\AgentMap\Discovery\RankedNode;
use voku\AgentMap\Discovery\RepositoryDiscoveryReport;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

final readonly class DiscoveryCliApplication
{
    private const DEFAULT_INDEX = '.agent-map/php-symbols.json';

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

            $tokens = array_slice($argv, 2);
            return match ($command) {
                'discover' => $this->discover($tokens),
                'rank' => $this->rank($tokens),
                'impact' => $this->impact($tokens),
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
        $regionQuery = $parsed['options']['region'] ?? null;
        if ($regionQuery !== null) {
            $region = $report->architecture->resolveRegion($regionQuery);
            $payload = [
                'type' => 'discover_region',
                'map_digest' => $report->mapDigest,
                'region' => $region->toArray(),
            ];
            echo $this->render($payload, $format, $this->regionText($region, $report->architecture, $limit));
            return 0;
        }

        $payload = [
            'type' => 'discover',
            ...$report->toArray(),
        ];
        echo $this->render($payload, $format, $this->discoveryText($report));
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

        echo $this->render($payload, $format, $this->rankText($metric, $ranked));
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
        $report = (new ImpactAnalyzer())->forMethod($map, $parsed['arguments'][0], $depth, $maximumNodes);
        $payload = [
            'type' => 'impact',
            ...$report->toArray(),
        ];

        echo $this->render($payload, $format, $this->impactText($report));
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
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
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
                $value = $tokens[$i + 1] ?? null;
                if (!is_string($value) || str_starts_with($value, '--')) {
                    throw new InvalidArgumentException('Missing value for option: --' . $name);
                }
                ++$i;
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

    private function discoveryText(RepositoryDiscoveryReport $report): string
    {
        $out = "PHP architecture discovery\n";
        $out .= 'Map: ' . $report->mapDigest . "\n";
        $out .= sprintf(
            "Relations: %d certain, %d uncertain, %d diagnostic(s)\n\n",
            $report->quality['certain_relations'],
            $report->quality['uncertain_relations'],
            $report->quality['diagnostics'],
        );
        $out .= $this->architectureSection($report->architecture);
        $out .= $this->rankedSection('Entrypoint candidates', $report->entrypointCandidates);
        $out .= $this->rankedSection('Call hubs', $report->callHubs);
        $out .= $this->rankedSection('Orchestrators', $report->orchestrators);
        $out .= $this->rankedSection('Type hubs', $report->typeHubs);
        $out .= $this->couplingSection('Namespace coupling', $report->namespaceCoupling);
        $out .= $this->couplingSection('Directory coupling', $report->directoryCoupling);
        $out .= $this->couplingSection('File coupling', $report->fileCoupling);

        return $out;
    }

    private function architectureSection(ArchitectureMapReport $architecture): string
    {
        $out = sprintf(
            "Architecture regions: %d region(s), %d level(s)\n",
            count($architecture->regions),
            $architecture->levels(),
        );
        if ($architecture->regions === []) {
            $out .= '  none inferred';
            if ($architecture->unassignedFiles !== []) {
                $out .= sprintf(' (%d unassigned file(s))', count($architecture->unassignedFiles));
            }
            return $out . "\n\n";
        }

        $byId = [];
        foreach ($architecture->regions as $region) {
            $byId[$region->id] = $region;
        }
        foreach ($architecture->rootRegionIds as $rootId) {
            $region = $byId[$rootId] ?? null;
            if ($region instanceof ArchitectureRegion) {
                $out .= $this->architectureRegionText($region, $byId, 1);
            }
        }
        if ($architecture->crosscutFiles !== []) {
            $out .= '  cross-cutting: ' . implode(', ', array_map(
                static fn (array $row): string => $row['file'] . '=' . number_format($row['score'], 2, '.', ''),
                array_slice($architecture->crosscutFiles, 0, 5),
            )) . "\n";
        }
        if ($architecture->unassignedFiles !== []) {
            $out .= sprintf('  unassigned: %d file(s)\n', count($architecture->unassignedFiles));
        }

        return $out . "\n";
    }

    /** @param array<string, ArchitectureRegion> $byId */
    private function architectureRegionText(ArchitectureRegion $region, array $byId, int $depth): string
    {
        $evidence = sprintf(
            'boundary=%.2f ns=%.2f dir=%.2f',
            $region->boundaryStrength,
            $region->namespaceAgreement,
            $region->directoryAgreement,
        );
        $signals = $region->dominantSignals === [] ? '' : ' signals=' . implode(',', $region->dominantSignals);
        $out = sprintf(
            "%s%s %s [%df] %s%s\n",
            str_repeat('  ', $depth),
            strtoupper($region->kind),
            $region->label,
            count($region->files),
            $evidence,
            $signals,
        );
        foreach ($region->childIds as $childId) {
            $child = $byId[$childId] ?? null;
            if ($child instanceof ArchitectureRegion) {
                $out .= $this->architectureRegionText($child, $byId, $depth + 1);
            }
        }

        return $out;
    }

    private function regionText(ArchitectureRegion $region, ArchitectureMapReport $architecture, int $limit): string
    {
        $out = sprintf("Region: %s (%s, level %d)\n", $region->label, $region->kind, $region->level);
        $path = $architecture->pathForFile($region->files[0]);
        $out .= 'Path: ' . implode(' > ', array_map(static fn (ArchitectureRegion $item): string => $item->label, array_reverse($path))) . "\n";
        $out .= sprintf(
            "Evidence: boundary=%.2f ratio=%.2f density=%.2f crosscut=%.2f namespace=%.2f directory=%.2f\n",
            $region->boundaryStrength,
            $region->boundaryRatio,
            $region->internalDensity,
            $region->crosscutScore,
            $region->namespaceAgreement,
            $region->directoryAgreement,
        );
        $out .= 'Signals: ' . ($region->dominantSignals === [] ? '(none)' : implode(', ', $region->dominantSignals)) . "\n";
        $out .= $this->stringListSection('Files', $region->files, $limit);
        $out .= $this->stringListSection('Interface files', $region->interfaceFiles, $limit);

        $children = [];
        foreach ($region->childIds as $childId) {
            foreach ($architecture->regions as $candidate) {
                if ($candidate->id === $childId) {
                    $children[] = $candidate->label;
                    break;
                }
            }
        }
        if ($children !== []) {
            $out .= $this->stringListSection('Children', $children, $limit);
        }

        return $out;
    }

    /** @param list<string> $items */
    private function stringListSection(string $title, array $items, int $limit): string
    {
        $out = $title . ':\n';
        foreach (array_slice($items, 0, $limit) as $item) {
            $out .= '  ' . $item . "\n";
        }
        if (count($items) > $limit) {
            $out .= sprintf("  ... %d more\n", count($items) - $limit);
        }

        return $out;
    }

    /** @param list<RankedNode> $rows */
    private function rankedSection(string $title, array $rows): string
    {
        $out = $title . ":\n";
        foreach ($rows as $row) {
            $out .= sprintf(
                "  %4d  ?%-3d  %s  %s:%d\n",
                $row->score,
                $row->uncertainRelations,
                $row->node->name,
                $row->node->file,
                $row->node->lineStart,
            );
        }

        return $out . "\n";
    }

    /** @param list<array{from: string, to: string, links: int, uncertain_links: int}> $rows */
    private function couplingSection(string $title, array $rows): string
    {
        $out = $title . ":\n";
        foreach ($rows as $row) {
            $out .= sprintf(
                "  %4d link(s), %3d uncertain  %s -> %s\n",
                $row['links'],
                $row['uncertain_links'],
                $row['from'],
                $row['to'],
            );
        }

        return $out . "\n";
    }

    /** @param list<RankedNode> $ranked */
    private function rankText(GraphMetric $metric, array $ranked): string
    {
        return $this->rankedSection('Rank by ' . $metric->value, $ranked);
    }

    private function impactText(ImpactReport $report): string
    {
        $out = 'Impact: ' . $report->target->name . "\n";
        if ($report->targetArchitecturePath !== []) {
            $out .= 'Target path: ' . implode(' > ', array_reverse($report->targetArchitecturePath)) . "\n";
        }
        if ($report->regionBuckets !== []) {
            $out .= "Propagation by region:\n";
            foreach ($report->regionBuckets as $bucket) {
                $out .= $this->impactRegionText($bucket);
            }
        }
        $out .= "Nodes:\n";
        foreach ($report->impacts as $impact) {
            $out .= sprintf(
                "  d=%d %s%s [%s] %s:%d\n",
                $impact->depth,
                $impact->uncertain ? '? ' : '  ',
                $impact->node->name,
                implode(',', $impact->relationKinds),
                $impact->node->file,
                $impact->node->lineStart,
            );
        }
        if ($report->truncated) {
            $out .= "TRUNCATED: increase --max-nodes for a complete bounded traversal.\n";
        }

        return $out;
    }

    private function impactRegionText(ImpactRegionBucket $bucket): string
    {
        $path = $bucket->pathLabels === [] ? $bucket->label : implode(' > ', array_reverse($bucket->pathLabels));
        return sprintf(
            "  %s: %d node(s), %d uncertain, depth<=%d\n",
            $path,
            count($bucket->nodeIds),
            $bucket->uncertainNodes,
            $bucket->maximumDepth,
        );
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
