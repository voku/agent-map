<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Plan\PlanCapability;
use voku\AgentMap\Rename\PropertyRenamePlan;
use voku\AgentMap\Rename\PropertyRenamePlanner;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;

/** Read-only CLI boundary for one versioned PHP property rename plan. */
final readonly class PropertyRenameCliApplication implements PlanCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    public function capability(): PlanCapability
    {
        return new PlanCapability(
            family: PlanCapability::FAMILY_RENAME,
            kind: 'property',
            command: 'property-rename-plan',
            planType: 'property_rename_plan',
            contractVersion: PropertyRenamePlan::CONTRACT_VERSION,
            semanticBackend: 'phpstan',
        );
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'property-rename-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'property-rename-plan');
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Property refactoring evidence:
  property-rename-plan Build a read-only, fail-closed private-property rename plan

Run `agent-map help property-rename-plan` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            if (($argv[1] ?? null) === 'help') {
                echo $this->help();
                return 0;
            }

            $parsed = $this->parse(array_slice($argv, 2));
            if ($parsed['help']) {
                echo $this->help();
                return 0;
            }
            if (count($parsed['arguments']) !== 2) {
                throw new InvalidArgumentException('property-rename-plan requires exactly Class::$property and replacementName.');
            }

            $indexPath = $parsed['options']['index'] ?? $this->artifacts->indexJson();
            $format = $this->format($parsed['options']['format'] ?? 'text');
            $map = (new IndexReader())->read($indexPath);
            $plan = (new PropertyRenamePlanner())->plan($map, $parsed['arguments'][0], $parsed['arguments'][1]);

            echo $this->render($plan, $format);

            return $plan->isBlocked() ? 1 : 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{arguments: list<string>, options: array<string, string>, help: bool}
     */
    private function parse(array $tokens): array
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

            if (!in_array($name, ['index', 'format'], true)) {
                throw new InvalidArgumentException('Unknown option: --' . $name);
            }
            if ($value === '') {
                throw new InvalidArgumentException('Empty value for option: --' . $name);
            }
            $options[$name] = $value;
        }

        return ['arguments' => $arguments, 'options' => $options, 'help' => $help];
    }

    private function format(string $format): string
    {
        if (!in_array($format, ['text', 'json', 'toon'], true)) {
            throw new InvalidArgumentException('Unknown output format: ' . $format);
        }

        return $format;
    }

    private function render(PropertyRenamePlan $plan, string $format): string
    {
        return match ($format) {
            'json' => json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'toon' => Toon::encode($plan->toArray()) . "\n",
            default => $this->text($plan),
        };
    }

    private function text(PropertyRenamePlan $plan): string
    {
        $lines = [
            sprintf('Property rename plan: %s', strtoupper($plan->status)),
            sprintf('Target: %s', $plan->targetId),
            sprintf('Rename: $%s -> $%s', $plan->originalName, $plan->replacementName),
            sprintf('Backend: %s', $plan->provenance->backend),
            sprintf('Map digest: %s', $plan->provenance->mapDigest),
            sprintf('Edits: %d', count($plan->edits)),
        ];
        foreach ($plan->edits as $edit) {
            $lines[] = $this->editLine($edit);
        }
        foreach ($plan->blindSpots as $blindSpot) {
            $lines[] = $this->blindSpotLine($blindSpot);
        }
        foreach ($plan->staleEvidence as $stale) {
            $lines[] = sprintf('  STALE [%s] %s', $stale->reason, $stale->path);
        }
        foreach ($plan->blockers as $blocker) {
            $lines[] = '  BLOCKER: ' . $blocker;
        }
        if ($plan->notObservable !== []) {
            $lines[] = 'Not observable:';
            foreach ($plan->notObservable as $item) {
                $lines[] = '  - ' . $item;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function editLine(RenameEdit $edit): string
    {
        return sprintf(
            '  edit %s:%d-%d bytes %d-%d [%s/%s] %s -> %s',
            $edit->path,
            $edit->lineStart,
            $edit->lineEnd,
            $edit->startFilePos,
            $edit->endFilePos,
            $edit->role,
            $edit->resolution,
            $edit->expected,
            $edit->replacement,
        );
    }

    private function blindSpotLine(RenameBlindSpot $blindSpot): string
    {
        $location = $blindSpot->path === null
            ? ''
            : sprintf(' %s:%d-%d', $blindSpot->path, $blindSpot->lineStart ?? 0, $blindSpot->lineEnd ?? $blindSpot->lineStart ?? 0);

        return sprintf('  REVIEW [%s]%s %s', $blindSpot->kind, $location, $blindSpot->message);
    }

    private function help(): string
    {
        return <<<'TEXT'
Usage: agent-map property-rename-plan 'Class::$property' replacementName [--index PATH] [--format text|json|toon]

Build a read-only property rename plan. The command never modifies source.

Contract 1.0 automatically plans only private, non-promoted properties without PHP 8.4 hooks when
PHPStan proves the declaration and every observed static/ordinary/nullsafe access. Dynamic property
names and magic dispatch require review. Public/protected properties, promoted properties, hooks,
collisions, stale sources, structural-only maps and ambiguous evidence fail closed.

Each edit carries source SHA-256, exact byte range and expected token. A later mutation boundary must
re-check every value immediately before applying anything.

TEXT;
    }
}
