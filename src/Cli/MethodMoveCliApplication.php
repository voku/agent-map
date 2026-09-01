<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Plan\PlanCapability;
use voku\AgentMap\Move\MethodMovePlan;
use voku\AgentMap\Move\MethodMovePlanner;

/** Read-only CLI boundary for exact method relocation evidence. */
final readonly class MethodMoveCliApplication implements PlanCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    public function capability(): PlanCapability
    {
        return new PlanCapability(
            family: PlanCapability::FAMILY_MOVE,
            kind: 'method',
            command: 'method-move-plan',
            planType: 'method_move_plan',
            contractVersion: MethodMovePlan::CONTRACT_VERSION,
            semanticBackend: 'phpstan',
        );
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Method move evidence:
  method-move-plan    Build an exact method relocation plan for a chosen destination

Run `agent-map help method-move-plan` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'method-move-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'method-move-plan');
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
                throw new InvalidArgumentException('method-move-plan requires one Class::method source and one destination class.');
            }

            $format = $this->format($parsed['options']['format'] ?? 'text');
            $explicitIndex = $parsed['options']['index'] ?? null;
            $index = $explicitIndex === null
                ? $this->artifacts->indexJson()
                : $this->artifacts->projectPath($explicitIndex);
            $map = (new IndexReader())->read($index);
            $plan = (new MethodMovePlanner())->plan($map, $parsed['arguments'][0], $parsed['arguments'][1]);
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
            if (isset($options[$name])) {
                throw new InvalidArgumentException('Duplicate option: --' . $name);
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

    private function render(MethodMovePlan $plan, string $format): string
    {
        if ($format === 'json') {
            return json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($format === 'toon') {
            return Toon::encode($plan->toArray()) . "\n";
        }

        $lines = [
            sprintf('Method move plan: %s', strtoupper($plan->status)),
            'Source: ' . $plan->sourceId,
            'Destination: ' . $plan->destinationFqn,
            'Edits: ' . count($plan->edits),
            'Provenance:',
            '  map_digest: ' . $plan->provenance->mapDigest,
            '  backend: ' . $plan->provenance->backend,
        ];
        if ($plan->provenance->analysisFingerprint !== null) {
            foreach ($plan->provenance->analysisFingerprint->toArray() as $name => $value) {
                $lines[] = '  analysis_fingerprint.' . $name . ': ' . $value;
            }
        }
        foreach ($plan->ownerDependencies as $dependency) {
            $lines[] = '  OWNER DEPENDENCY: ' . $dependency;
        }
        foreach ($plan->edits as $edit) {
            $lines[] = sprintf('  %s %s:%d-%d bytes %d-%d (SHA-256 %s)', $edit->role, $edit->path, $edit->lineStart, $edit->lineEnd, $edit->startFilePos, $edit->endFilePos, $edit->sourceSha256);
        }
        foreach ($plan->blindSpots as $spot) {
            $lines[] = '  REVIEW: ' . $spot->message;
        }
        foreach ($plan->staleEvidence as $stale) {
            $lines[] = sprintf('  STALE: %s (%s)', $stale->path, $stale->reason);
        }
        foreach ($plan->blockers as $blocker) {
            $lines[] = '  BLOCKER: ' . $blocker;
        }
        foreach ($plan->notObservable as $boundary) {
            $lines[] = '  NOT OBSERVABLE: ' . $boundary;
        }

        return implode("\n", $lines) . "\n";
    }

    private function help(): string
    {
        return <<<'TEXT'
Usage: agent-map method-move-plan Class::method DestinationClass [--index PATH] [--format text|json|toon]

Plan the exact relocation of one already-chosen method into one already-chosen destination class. The
command never changes source, never suggests which method to move, and never proposes a destination.

The first slice supports static methods whose body observes no owner state. A body using $this, self::,
static::, parent::, __CLASS__ or get_called_class() is blocked, because relocating that text would
silently change what those resolve to. Trait sources, abstract methods, an unresolvable or ambiguous
destination, a destination that already declares the name, stale source and conflicting declarations
also block. Public or protected sources and method attributes require review, because no closed-world
evidence here disproves a caller outside the indexed map.

Published edits are hash-guarded: the source declaration removal, the destination insertion before the
class closing brace, and one owner rewrite per statically resolved call site. Call sites are re-pointed
with the fully qualified destination so the plan never invents an import.

TEXT;
    }
}
