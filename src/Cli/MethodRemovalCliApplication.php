<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Removal\MethodRemovalPlan;
use voku\AgentMap\Removal\MethodRemovalPlanner;

/** Read-only CLI boundary for exact unused-private-method removal evidence. */
final readonly class MethodRemovalCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
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
            if (($argv[1] ?? null) === 'help' || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
                echo $this->help();
                return 0;
            }
            $target = $argv[2] ?? null;
            if (!is_string($target) || $target === '' || str_starts_with($target, '--')) {
                throw new InvalidArgumentException('method-removal-plan requires exactly one Class::method target.');
            }
            $options = [];
            foreach (array_slice($argv, 3) as $token) {
                if (!str_starts_with($token, '--') || !str_contains($token, '=')) {
                    throw new InvalidArgumentException('Options must use --name=value syntax: ' . $token);
                }
                [$name, $value] = explode('=', substr($token, 2), 2);
                if (!in_array($name, ['index', 'format'], true) || $value === '') {
                    throw new InvalidArgumentException('Unknown or empty option: --' . $name);
                }
                $options[$name] = $value;
            }
            $format = $options['format'] ?? 'text';
            if (!in_array($format, ['text', 'json', 'toon'], true)) {
                throw new InvalidArgumentException('Unknown output format: ' . $format);
            }
            $map = (new IndexReader())->read($options['index'] ?? $this->artifacts->indexJson());
            $plan = (new MethodRemovalPlanner())->plan($map, $target);
            echo $this->render($plan, $format);

            return $plan->isBlocked() ? 1 : 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    private function render(MethodRemovalPlan $plan, string $format): string
    {
        if ($format === 'json') {
            return json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($format === 'toon') {
            return Toon::encode($plan->toArray()) . "\n";
        }
        $lines = [sprintf('Method removal plan: %s', strtoupper($plan->status)), 'Target: ' . $plan->targetId, 'Edits: ' . count($plan->edits)];
        foreach ($plan->edits as $edit) {
            $lines[] = sprintf('  delete %s:%d-%d bytes %d-%d (SHA-256 %s)', $edit->path, $edit->lineStart, $edit->lineEnd, $edit->startFilePos, $edit->endFilePos, $edit->sourceSha256);
        }
        foreach ($plan->blindSpots as $spot) {
            $lines[] = '  REVIEW: ' . $spot->message;
        }
        foreach ($plan->blockers as $blocker) {
            $lines[] = '  BLOCKER: ' . $blocker;
        }

        return implode("\n", $lines) . "\n";
    }

    private function help(): string
    {
        return <<<'TEXT'
Usage: agent-map method-removal-plan Class::method [--index=PATH] [--format=text|json|toon]

Plan the exact whole-line deletion of an unused private PHP method. The command never changes source.
PHPStan must prove that no indexed call reaches the method. Calls, non-private contracts, stale source,
and conflicting declarations block the plan; dynamic dispatch on the owning type requires review.

TEXT;
    }
}
