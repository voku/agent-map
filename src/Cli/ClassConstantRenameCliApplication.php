<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Plan\PlanCapability;
use voku\AgentMap\Rename\ClassConstantRenamePlan;
use voku\AgentMap\Rename\ClassConstantRenamePlanner;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;

/** Read-only CLI boundary for one versioned PHP class-constant rename plan. */
final readonly class ClassConstantRenameCliApplication implements PlanCliApplication
{
    private MapArtifactPaths $artifacts;

    /** Uses explicit artifact paths or the current project defaults. */
    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    /** Advertises the versioned class-constant rename contract through the shared capability registry. */
    public function capability(): PlanCapability
    {
        return new PlanCapability(
            family: PlanCapability::FAMILY_RENAME,
            kind: 'class_constant',
            command: 'class-constant-rename-plan',
            planType: 'class_constant_rename_plan',
            contractVersion: ClassConstantRenamePlan::CONTRACT_VERSION,
            semanticBackend: 'none',
        );
    }

    /** @param list<string> $argv CLI arguments including the executable name. */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'class-constant-rename-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'class-constant-rename-plan');
    }

    /** @param list<string> $argv CLI arguments including the executable name. */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    /** Returns the concise entry appended to general CLI help. */
    public function helpOverview(): string
    {
        return <<<'TEXT'

Class-constant refactoring evidence:
  class-constant-rename-plan Build a read-only, fail-closed class-constant rename plan from current map evidence

Run `agent-map help class-constant-rename-plan` for details.
TEXT;
    }

    /** @param list<string> $argv CLI arguments including the executable name. */
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
                throw new InvalidArgumentException('class-constant-rename-plan requires exactly ClassName::CONSTANT and replacementName.');
            }

            $indexPath = $parsed['options']['index'] ?? $this->artifacts->indexJson();
            $format = $this->format($parsed['options']['format'] ?? 'text');
            $map = (new IndexReader())->read($indexPath);
            $plan = (new ClassConstantRenamePlanner())->plan($map, $parsed['arguments'][0], $parsed['arguments'][1]);

            echo $this->render($plan, $format);

            return $plan->isBlocked() ? 1 : 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * Parses positional target/replacement arguments and the supported read-only options.
     *
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

    /** @return 'text'|'json'|'toon' */
    private function format(string $format): string
    {
        if (!in_array($format, ['text', 'json', 'toon'], true)) {
            throw new InvalidArgumentException('Unknown output format: ' . $format);
        }

        return $format;
    }

    /** Renders the immutable plan without changing project source. */
    private function render(ClassConstantRenamePlan $plan, string $format): string
    {
        return match ($format) {
            'json' => json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'toon' => Toon::encode($plan->toArray()) . "\n",
            default => $this->text($plan),
        };
    }

    /** Renders the human-readable plan, including all review and blocker evidence. */
    private function text(ClassConstantRenamePlan $plan): string
    {
        $lines = [
            sprintf('Class constant rename plan: %s', strtoupper($plan->status)),
            sprintf('Target: %s', $plan->targetId),
            sprintf('Rename: %s -> %s', $plan->ownerFqn . '::' . $plan->originalName, $plan->replacementName),
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

    /** Formats one exact preconditioned edit for text output. */
    private function editLine(PlanEdit $edit): string
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

    /** Formats one review-required blind spot for text output. */
    private function blindSpotLine(PlanBlindSpot $blindSpot): string
    {
        $location = $blindSpot->path === null
            ? ''
            : sprintf(' %s:%d-%d', $blindSpot->path, $blindSpot->lineStart ?? 0, $blindSpot->lineEnd ?? $blindSpot->lineStart ?? 0);

        return sprintf('  REVIEW [%s]%s %s', $blindSpot->kind, $location, $blindSpot->message);
    }

    /** Returns detailed command help describing the exact fail-closed boundary. */
    private function help(): string
    {
        return <<<'TEXT'
Usage: agent-map class-constant-rename-plan ClassName::CONSTANT replacementName [--index PATH] [--format text|json|toon]

Build a read-only class-constant rename plan. The command never modifies source.

The contract renames the declaration and fetches whose declaring owner can be proven exactly.
Dynamic names or owners, late-bound static:: fetches, and same-name inherited or parent lookups
require review. Stale sources, declaration collisions, and ambiguous evidence fail closed.

Each edit carries the indexed source SHA-256 plus the exact byte range and expected token. A later
mutation boundary must re-check those values before applying anything.

TEXT;
    }
}
