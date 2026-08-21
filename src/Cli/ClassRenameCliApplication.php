<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Rename\ClassRenamePlan;
use voku\AgentMap\Rename\ClassRenamePlanner;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;
use voku\AgentMap\Rename\RenameMove;

/** Read-only CLI boundary for one versioned same-namespace PHP class rename plan. */
final readonly class ClassRenameCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'class-rename-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'class-rename-plan');
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Class refactoring evidence:
  class-rename-plan Build a read-only, fail-closed same-namespace class rename plan

Run `agent-map help class-rename-plan` for details.
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
                throw new InvalidArgumentException('class-rename-plan requires exactly className and replacementName.');
            }

            $indexPath = $parsed['options']['index'] ?? $this->artifacts->indexJson();
            $format = $this->format($parsed['options']['format'] ?? 'text');
            $map = (new IndexReader())->read($indexPath);
            $plan = (new ClassRenamePlanner())->plan($map, $parsed['arguments'][0], $parsed['arguments'][1]);

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

    private function render(ClassRenamePlan $plan, string $format): string
    {
        return match ($format) {
            'json' => json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'toon' => Toon::encode($plan->toArray()) . "\n",
            default => $this->text($plan),
        };
    }

    private function text(ClassRenamePlan $plan): string
    {
        $lines = [
            sprintf('Class rename plan: %s', strtoupper($plan->status)),
            sprintf('Target: %s', $plan->targetId),
            sprintf('Rename: %s -> %s', $plan->originalFqn, $plan->replacementFqn),
            sprintf('Backend: %s', $plan->provenance->backend),
            sprintf('Map digest: %s', $plan->provenance->mapDigest),
            sprintf('Edits: %d', count($plan->edits)),
            sprintf('Moves: %d', count($plan->moves)),
        ];
        foreach ($plan->edits as $edit) {
            $lines[] = $this->editLine($edit);
        }
        foreach ($plan->moves as $move) {
            $lines[] = $this->moveLine($move);
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

    private function moveLine(RenameMove $move): string
    {
        return sprintf('  move %s -> %s [%s]', $move->fromPath, $move->toPath, $move->sourceSha256);
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
Usage: agent-map class-rename-plan ClassName replacementName [--index PATH] [--format text|json|toon]

Build a read-only same-namespace class rename plan. The command never modifies source.

The initial contract rewrites parser-resolved PHP class declarations, imports and static name references.
PHPDoc/class-string evidence, unconventional autoload paths and multi-symbol file moves require review.
Namespace moves, collisions, stale sources and ambiguous source evidence fail closed.

Each edit carries the indexed source SHA-256 plus exact byte range and expected token. Conventional
ClassName.php source files may additionally project a preconditioned same-directory file move.
A later mutation boundary must re-check all evidence before applying anything.

TEXT;
    }
}
