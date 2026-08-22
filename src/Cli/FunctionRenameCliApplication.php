<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Rename\FunctionRenamePlan;
use voku\AgentMap\Rename\FunctionRenamePlanner;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;

/** Read-only CLI boundary for one versioned PHP function rename plan. */
final readonly class FunctionRenameCliApplication implements RenamePlanCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    public function capability(): RenamePlanCapability
    {
        return new RenamePlanCapability(
            kind: 'function',
            command: 'function-rename-plan',
            planType: 'function_rename_plan',
            contractVersion: FunctionRenamePlan::CONTRACT_VERSION,
        );
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'function-rename-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'function-rename-plan');
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Function refactoring evidence:
  function-rename-plan Build a read-only, fail-closed function rename plan from current map evidence

Run `agent-map help function-rename-plan` for details.
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
                throw new InvalidArgumentException('function-rename-plan requires exactly functionName and replacementName.');
            }

            $indexPath = $parsed['options']['index'] ?? $this->artifacts->indexJson();
            $format = $this->format($parsed['options']['format'] ?? 'text');
            $map = (new IndexReader())->read($indexPath);
            $plan = (new FunctionRenamePlanner())->plan($map, $parsed['arguments'][0], $parsed['arguments'][1]);

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

    private function render(FunctionRenamePlan $plan, string $format): string
    {
        return match ($format) {
            'json' => json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'toon' => Toon::encode($plan->toArray()) . "\n",
            default => $this->text($plan),
        };
    }

    private function text(FunctionRenamePlan $plan): string
    {
        $lines = [
            sprintf('Function rename plan: %s', strtoupper($plan->status)),
            sprintf('Target: %s', $plan->targetId),
            sprintf('Rename: %s -> %s', $plan->originalName, $plan->replacementName),
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
Usage: agent-map function-rename-plan functionName replacementName [--index PATH] [--format text|json|toon]

Build a read-only function rename plan. The command never modifies source.

The initial contract supports a short replacement name in the target function's existing namespace.
A plan is SAFE only when PHPStan resolves each observed call to that function and each declaration/call
maps to one exact unqualified source token. Dynamic calls require review; aliases, qualified/FQN call
spellings, stale sources, collisions, structural-only maps, and ambiguous evidence fail closed.

Each edit carries the indexed source SHA-256 plus the exact byte range and expected token. A later
mutation boundary must re-check those values before applying anything.

TEXT;
    }
}
