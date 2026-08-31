<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Move\ClassMovePlan;
use voku\AgentMap\Move\ClassMovePlanner;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;
use voku\AgentMap\Rename\RenameMove;

/** Read-only CLI boundary for deterministic PHP class namespace relocation planning. */
final readonly class ClassMoveCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'class-move-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'class-move-plan');
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
                throw new InvalidArgumentException('class-move-plan requires exactly a current class FQN and a destination class FQN.');
            }

            $indexPath = $parsed['options']['index'] ?? $this->artifacts->indexJson();
            $format = $this->format($parsed['options']['format'] ?? 'text');
            $map = (new IndexReader())->read($indexPath);
            $plan = (new ClassMovePlanner())->plan($map, $parsed['arguments'][0], $parsed['arguments'][1]);

            echo $this->render($plan, $format);

            return $plan->isBlocked() ? 1 : 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");

            return 1;
        }
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Class relocation evidence:
  class-move-plan Build a read-only class namespace move plan with PSR-4 destination evidence

Run `agent-map help class-move-plan` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
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

    private function render(ClassMovePlan $plan, string $format): string
    {
        return match ($format) {
            'json' => json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'toon' => Toon::encode($plan->toArray()) . "\n",
            default => $this->text($plan),
        };
    }

    private function text(ClassMovePlan $plan): string
    {
        $lines = [
            sprintf('Class move plan: %s', strtoupper($plan->status)),
            sprintf('Target: %s', $plan->targetId),
            sprintf('Move: %s -> %s', $plan->sourceFqn, $plan->destinationFqn),
            sprintf('Backend: %s', $plan->provenance->backend),
            sprintf('Edits: %d', count($plan->edits)),
            sprintf('Moves: %d', count($plan->moves)),
        ];

        if ($plan->autoload !== null) {
            $lines[] = sprintf(
                'Autoload: %s [%s] %s -> %s',
                $plan->autoload->manifestPath,
                $plan->autoload->manifestSha256,
                $plan->autoload->sourcePrefix === '' ? '""' : $plan->autoload->sourcePrefix,
                $plan->autoload->destinationPrefix === '' ? '""' : $plan->autoload->destinationPrefix,
            );
        }

        foreach ($plan->edits as $edit) {
            $lines[] = $this->editLine($edit);
        }
        foreach ($plan->moves as $move) {
            $lines[] = $this->moveLine($move);
        }
        foreach ($plan->blindSpots as $blindSpot) {
            $lines[] = $this->blindSpotLine($blindSpot);
        }
        foreach ($plan->blockers as $blocker) {
            $lines[] = '  BLOCKER: ' . $blocker;
        }
        foreach ($plan->staleEvidence as $stale) {
            $lines[] = sprintf('  STALE %s: %s', $stale->path, $stale->reason);
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
Usage: agent-map class-move-plan CurrentClassFqn DestinationClassFqn [--index PATH] [--format text|json|toon]

Build a read-only `class_move_plan@1.0` for one class that is already known to belong in another
namespace. The command never modifies source, never moves files and never rewrites composer.json.

The destination file path is derived from the project's declared Composer PSR-4 mappings, so both the
current location and the destination identity have to be explained by exactly one deterministic
mapping. The plan publishes the namespace declaration edit, exact import and reference edits across
indexed PHP, and one preconditioned file move bound to the pre-edit SHA-256.

References that resolved through the enclosing namespace instead of an import are pinned to fully
qualified names rather than by synthesizing new imports, and are reported as REVIEW_REQUIRED evidence.
PHPDoc, exact class-name string literals, dynamic class names, `__NAMESPACE__`, unqualified function
or constant fallbacks, shadowed PSR-4 prefixes and autoload-section changes are review evidence too.

Stale source, destination identity or file collisions, grouped imports of the moved class, multi-symbol
or multi-namespace files, braced namespaces, global-namespace sources, classmap layouts and ambiguous
PSR-4 evidence are BLOCKED without publishing any edit or move.

Class renaming stays with `class-rename-plan`: this contract keeps the class name and changes only the
namespace. A mutation host must validate the complete precondition set before applying anything.

TEXT;
    }
}
