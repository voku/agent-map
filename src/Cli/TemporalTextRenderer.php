<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use voku\AgentMap\Temporal\CoChangeReport;
use voku\AgentMap\Temporal\EntityHistoryReport;
use voku\AgentMap\Temporal\TemporalDiffReport;
use voku\AgentMap\Temporal\TemporalEvent;

final readonly class TemporalTextRenderer
{
    public function diff(TemporalDiffReport $report, int $limit): string
    {
        $out = "Temporal structural diff\n\n";
        $out .= 'Before: ' . $report->beforeDigest . "\n";
        $out .= 'After:  ' . $report->afterDigest . "\n";
        $out .= 'Events: ' . count($report->events) . "\n\n";
        $out .= "Counts:\n";
        foreach ($report->countsByKind() as $kind => $count) {
            $out .= sprintf("  %-32s %d\n", $kind, $count);
        }

        $out .= "\nEvents:\n";
        foreach (array_slice($report->events, 0, max(1, $limit)) as $event) {
            $out .= '  ' . $event->kind . ' ' . $event->entityId . "\n";
            $out .= $this->evidenceLine('before', $event, true);
            $out .= $this->evidenceLine('after', $event, false);
        }
        if (count($report->events) > $limit) {
            $out .= '  ... ' . (count($report->events) - $limit) . " more event(s)\n";
        }

        return $out;
    }

    public function coupling(CoChangeReport $report): string
    {
        $out = "Temporal co-change evidence\n\n";
        $out .= 'Commits analysed: ' . $report->commitsAnalyzed . "\n";
        $out .= 'Bulk commits skipped: ' . $report->bulkCommitsSkipped . "\n";
        $out .= 'Pairs: ' . count($report->pairs) . "\n\n";

        foreach ($report->pairs as $pair) {
            $out .= $pair->left . ' <> ' . $pair->right . "\n";
            $out .= sprintf(
                "  cochanges=%d; left_changes=%d; right_changes=%d; jaccard=%.3f; smaller_side_ratio=%.3f\n",
                $pair->coChanges,
                $pair->leftChanges,
                $pair->rightChanges,
                $pair->jaccard,
                $pair->smallerSideRatio,
            );
            $out .= sprintf(
                "  static: semantic_weight=%.3f; path_weight=%.3f%s\n",
                $pair->semanticStaticWeight,
                $pair->pathStaticWeight,
                $pair->staticSignals === [] ? '' : '; signals=' . implode(',', array_keys($pair->staticSignals)),
            );
        }

        return $out;
    }

    public function entityHistory(EntityHistoryReport $report): string
    {
        $out = "Temporal entity history\n\n";
        $out .= 'Entity: ' . $report->entityId . "\n";
        $out .= 'Status: ' . $report->status() . "\n";
        $out .= 'Observations: ' . count($report->observations) . "\n";
        $delta = $report->metricDelta();
        $out .= sprintf(
            "Metric delta: callers=%+d; callees=%+d; dependents=%+d; dependencies=%+d\n\n",
            $delta['callers'],
            $delta['callees'],
            $delta['dependents'],
            $delta['dependencies'],
        );

        foreach ($report->observations as $observation) {
            $out .= substr($observation['revision'], 0, 12) . ' ' . $observation['committed_at'] . "\n";
            $out .= '  path=' . $observation['file_path'] . '; region=' . ($observation['region_label'] ?? 'unassigned') . "\n";
            $out .= sprintf(
                "  callers=%d; callees=%d; dependents=%d; dependencies=%d\n",
                $observation['callers'],
                $observation['callees'],
                $observation['dependents'],
                $observation['dependencies'],
            );
            $out .= '  signature=' . $observation['signature'] . "\n";
        }

        return $out;
    }

    private function evidenceLine(string $label, TemporalEvent $event, bool $before): string
    {
        $values = $before ? $event->before : $event->after;
        if ($values === []) {
            return '';
        }

        $parts = [];
        foreach ($values as $key => $value) {
            $parts[] = $key . '=' . match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return '    ' . $label . ': ' . implode('; ', $parts) . "\n";
    }
}
