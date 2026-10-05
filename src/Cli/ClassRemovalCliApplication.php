<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Plan\PlanCapability;
use voku\AgentMap\Removal\ClassRemovalPlan;
use voku\AgentMap\Removal\ClassRemovalPlanner;

/** Read-only CLI boundary for exact unused-class whole-file deletion evidence. */
final readonly class ClassRemovalCliApplication implements PlanCliApplication
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
            kind: 'class',
            command: 'class-removal-plan',
            planType: ClassRemovalPlan::PLAN_TYPE,
            contractVersion: ClassRemovalPlan::CONTRACT_VERSION,
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

Class removal evidence:
  class-removal-plan Build an exact unused-class owned-file deletion plan

Run agent-map help class-removal-plan for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'class-removal-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'class-removal-plan');
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
                throw new InvalidArgumentException('class-removal-plan requires exactly one class target.');
            }

            $format = $this->format($parsed['options']['format'] ?? 'text');
            $explicitIndex = $parsed['options']['index'] ?? null;
            $index = $explicitIndex === null ? $this->artifacts->indexJson() : $this->artifacts->projectPath($explicitIndex);
            $plan = (new ClassRemovalPlanner())->plan((new IndexReader())->read($index), $parsed['arguments'][0]);
            echo $this->render($plan, $format);

            return $plan->isBlocked() ? 1 : 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /** @param list<string> $tokens @return array{arguments: list<string>, options: array<string, string>, help: bool} */
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

    private function render(ClassRemovalPlan $plan, string $format): string
    {
        if ($format === 'json') {
            return json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($format === 'toon') {
            return Toon::encode($plan->toArray()) . "\n";
        }

        $lines = [
            sprintf('Class removal plan: %s', strtoupper($plan->status)),
            'Target: ' . $plan->targetId,
            'File deletions: ' . count($plan->deletions),
        ];
        foreach ($plan->deletions as $deletion) {
            $lines[] = sprintf('  delete file %s (SHA-256 %s)', $deletion->path, $deletion->sourceSha256);
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
Usage: agent-map class-removal-plan ClassName [--index PATH] [--format text|json|toon]

Plan deletion of one unused namespaced PHP class together with its complete owned source file. The command never changes source.
Contract 1.0 requires a PHPStan-backed map, one ordinary class as the file's only indexed symbol, no incoming usage
evidence, and no independently owned top-level PHP statements. Class attributes and exact string/PHPDoc references require
review. Interfaces, traits, enums, multi-symbol files, braced/global namespaces, stale evidence, and live references fail closed.

TEXT;
    }
}
