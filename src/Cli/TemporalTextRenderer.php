<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

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
