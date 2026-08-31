<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Plan\PlanCapability;
use voku\AgentMap\Removal\MethodRemovalPlan;
use voku\AgentMap\Removal\MethodRemovalPlanner;

/** Read-only CLI boundary for exact unused-private-method removal evidence. */
final readonly class MethodRemovalCliApplication implements PlanCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    public function capability(): PlanCapability
    {
        return new PlanCapability(
            family: PlanCapability::FAMILY_REMOVAL,
            kind: 'method',
            command: 'method-removal-plan',
            planType: 'method_removal_plan',
            contractVersion: MethodRemovalPlan::CONTRACT_VERSION,
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

Method removal evidence:
  method-removal-plan Build an exact unused-private-method deletion plan

Run `agent-map help method-removal-plan` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'method-removal-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'method-removal-plan');
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
            if (count($parsed['arguments']) !== 1) {
                throw new InvalidArgumentException('method-removal-plan requires exactly one Class::method target.');
            }

            $format = $this->format($parsed['options']['format'] ?? 'text');
            $explicitIndex = $parsed['options']['index'] ?? null;
            $index = $explicitIndex === null
                ? $this->artifacts->indexJson()
                : $this->artifacts->projectPath($explicitIndex);
            $map = (new IndexReader())->read($index);
            $plan = (new MethodRemovalPlanner())->plan($map, $parsed['arguments'][0]);
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

    private function render(MethodRemovalPlan $plan, string $format): string
    {
        if ($format === 'json') {
            return json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($format === 'toon') {
            return Toon::encode($plan->toArray()) . "\n";
        }

        $lines = [
            sprintf('Method removal plan: %s', strtoupper($plan->status)),
            'Target: ' . $plan->targetId,
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
        foreach ($plan->edits as $edit) {
            $lines[] = sprintf('  delete %s:%d-%d bytes %d-%d (SHA-256 %s)', $edit->path, $edit->lineStart, $edit->lineEnd, $edit->startFilePos, $edit->endFilePos, $edit->sourceSha256);
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
Usage: agent-map method-removal-plan Class::method [--index PATH] [--format text|json|toon]

Plan the exact whole-line deletion of an unused private PHP method. The command never changes source.
PHPStan must prove that no indexed call reaches the method. Calls, non-private contracts, stale source,
traits, magic methods/dispatch, unresolved class-string static calls, conflicting declarations, and
unsafe same-line source block the plan. Dynamic dispatch and method attributes require review.

TEXT;
    }
}
