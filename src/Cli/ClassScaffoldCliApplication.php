<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Plan\PlanCapability;
use voku\AgentMap\Scaffold\ClassScaffoldPlan;
use voku\AgentMap\Scaffold\ClassScaffoldPlanner;

/** Read-only CLI boundary for class scaffolding evidence. */
final readonly class ClassScaffoldCliApplication implements PlanCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(?MapArtifactPaths $artifacts = null)
    {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject(getcwd() ?: '.');
    }

    public function capability(): PlanCapability
    {
        return new PlanCapability(
            family: PlanCapability::FAMILY_SCAFFOLD,
            kind: 'class',
            command: 'class-scaffold-plan',
            planType: ClassScaffoldPlan::PLAN_TYPE,
            contractVersion: ClassScaffoldPlan::CONTRACT_VERSION,
            semanticBackend: 'none',
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

Class scaffold evidence:
  class-scaffold-plan   Build a clean class/interface/trait/enum scaffolding plan

Run `agent-map help class-scaffold-plan` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        return ($argv[1] ?? null) === 'class-scaffold-plan'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'class-scaffold-plan');
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
                throw new InvalidArgumentException('class-scaffold-plan requires one fully qualified class name.');
            }

            $format = $this->format($parsed['options']['format'] ?? 'text');
            $explicitIndex = $parsed['options']['index'] ?? null;
            $index = $explicitIndex === null
                ? $this->artifacts->indexJson()
                : $this->artifacts->projectPath($explicitIndex);
            $map = (new IndexReader())->read($index);

            $final = !isset($parsed['flags']['no-final']);
            $readonly = isset($parsed['flags']['readonly']);

            $plan = (new ClassScaffoldPlanner())->plan(
                map: $map,
                fqn: $parsed['arguments'][0],
                type: $parsed['options']['type'] ?? 'class',
                final: $final,
                readonly: $readonly,
                extends: $parsed['options']['extends'] ?? null,
                implements: $parsed['implements'],
                docSummary: $parsed['options']['doc'] ?? null,
                destinationPath: $parsed['options']['dest'] ?? null,
            );
            echo $this->render($plan, $format);

            return $plan->isBlocked() ? 1 : 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{arguments: list<string>, options: array<string, string>, flags: array<string, bool>, implements: list<string>, help: bool}
     */
    private function parse(array $tokens): array
    {
        $arguments = [];
        $options = [];
        $flags = [];
        $implements = [];
        $help = false;

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '-h' || $token === '--help') {
                $help = true;
                continue;
            }
            if ($token === '--final') {
                $flags['final'] = true;
                continue;
            }
            if ($token === '--no-final') {
                $flags['no-final'] = true;
                continue;
            }
            if ($token === '--readonly') {
                $flags['readonly'] = true;
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

            if ($name === 'implements') {
                $implements[] = $value;
                continue;
            }

            if (!in_array($name, ['index', 'format', 'type', 'extends', 'doc', 'dest'], true)) {
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

        return ['arguments' => $arguments, 'options' => $options, 'flags' => $flags, 'implements' => $implements, 'help' => $help];
    }

    /** @return 'text'|'json'|'toon' */
    private function format(string $format): string
    {
        if (!in_array($format, ['text', 'json', 'toon'], true)) {
            throw new InvalidArgumentException('Unknown output format: ' . $format);
        }

        return $format;
    }

    private function render(ClassScaffoldPlan $plan, string $format): string
    {
        if ($format === 'json') {
            return json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($format === 'toon') {
            return Toon::encode($plan->toArray()) . "\n";
        }

        $lines = [
            sprintf('Class scaffold plan: %s', strtoupper($plan->status)),
            'FQN: ' . $plan->fqn,
            'Destination: ' . $plan->destinationPath,
            'Edits: ' . count($plan->edits),
            'Provenance:',
            '  map_digest: ' . $plan->provenance->mapDigest,
            '  backend: ' . $plan->provenance->backend,
        ];
        foreach ($plan->edits as $edit) {
            $lines[] = sprintf('  %s %s (role: %s)', $edit->role, $edit->path, $edit->role);
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
Usage: agent-map class-scaffold-plan FQN [--type=class|interface|trait|enum] [--no-final] [--readonly] [--extends=Parent] [--implements=Interface] [--doc="Summary"] [--dest=PATH] [--index PATH] [--format text|json|toon]

Plan the creation of a new class, interface, trait, or enum.
Resolves destination file path via PSR-4 autoload mappings if not explicitly provided,
inserts strict types and imports, verifies PHP syntax, and emits a governed file creation edit.

TEXT;
    }
}
