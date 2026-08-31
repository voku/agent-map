<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use InvalidArgumentException;
use Throwable;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Plan\PlanCapability;

/** Public routing boundary for standalone and embedded agent-map. */
final readonly class CliApplication
{
    public function __construct(
        private ?string $projectRoot = null,
        private ?string $mapRoot = null,
    ) {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $artifacts = $this->projectRoot === null && $this->mapRoot === null
            ? null
            : MapArtifactPaths::forProject($this->projectRoot ?? (getcwd() ?: '.'), $this->mapRoot);

        $planApplications = $this->planApplications($artifacts);
        if (($argv[1] ?? null) === 'plan-capabilities'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'plan-capabilities')) {
            return $this->planCapabilities($argv, $planApplications);
        }

        $temporal = new TemporalCliApplication(artifacts: $artifacts);
        if ($temporal->supports($argv)) {
            return $temporal->run($argv);
        }

        foreach ($planApplications as $planApplication) {
            if ($planApplication->supports($argv)) {
                return $planApplication->run($argv);
            }
        }

        $discovery = new DiscoveryCliApplication(artifacts: $artifacts);
        if ($discovery->supports($argv)) {
            return $discovery->run($argv);
        }

        $status = (new AgentMapApplication(artifacts: $artifacts, defaultRoot: $this->projectRoot))->run($argv);
        $rest = array_slice($argv, 2);
        $generalHelp = in_array($argv[1] ?? null, ['help', '--help', '-h'], true)
            || in_array('--help', $rest, true)
            || in_array('-h', $rest, true);
        if ($generalHelp) {
            echo "\nArtifact paths:\n  --out, --index, and --database are relative to --root unless an absolute path is given.\n\n";
            echo "Plan capability discovery:\n  plan-capabilities List every governed rename, removal and move contract\n\n";
        }
        if ($temporal->shouldAppendToGeneralHelp($argv)) {
            echo $temporal->helpOverview();
        }
        foreach ($planApplications as $planApplication) {
            if ($planApplication->shouldAppendToGeneralHelp($argv)) {
                echo $planApplication->helpOverview();
            }
        }
        if ($discovery->shouldAppendToGeneralHelp($argv)) {
            echo $discovery->helpOverview();
        }

        return $status;
    }

    /**
     * Every governed plan boundary, in one list.
     *
     * Routing and `plan-capabilities` read the same list on purpose: a contract that is reachable on
     * the command line but invisible to capability discovery is exactly the drift this prevents.
     *
     * @return list<PlanCliApplication>
     */
    private function planApplications(?MapArtifactPaths $artifacts): array
    {
        return [
            new ClassMoveCliApplication(artifacts: $artifacts),
            new ClassRenameCliApplication(artifacts: $artifacts),
            new ClassConstantRenameCliApplication(artifacts: $artifacts),
            new ClassConstantRemovalCliApplication(artifacts: $artifacts),
            new FunctionRenameCliApplication(artifacts: $artifacts),
            new MethodRemovalCliApplication(artifacts: $artifacts),
            new ParameterRenameCliApplication(artifacts: $artifacts),
            new PropertyRenameCliApplication(artifacts: $artifacts),
            new PropertyRemovalCliApplication(artifacts: $artifacts),
            new RenameCliApplication(artifacts: $artifacts),
        ];
    }

    /**
     * @param list<string> $argv
     * @param list<PlanCliApplication> $applications
     */
    private function planCapabilities(array $argv, array $applications): int
    {
        try {
            if (($argv[1] ?? null) === 'help' || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
                echo <<<'TEXT'
Usage: agent-map plan-capabilities [--format text|json]

List every governed plan contract registered at the public CLI routing boundary: the rename, removal
and move families, their commands, contract versions and the semantic backend each one needs.
The output describes evidence planning only; agent-map remains read-only.

TEXT;
                return 0;
            }

            $format = 'text';
            $tokens = array_slice($argv, 2);
            for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
                $token = $tokens[$index];
                if ($token === '--format') {
                    $value = $tokens[$index + 1] ?? null;
                    if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                        throw new InvalidArgumentException('Missing value for option: --format');
                    }
                    $format = $value;
                    ++$index;
                    continue;
                }
                if (str_starts_with($token, '--format=')) {
                    $format = substr($token, strlen('--format='));
                    continue;
                }

                throw new InvalidArgumentException('Unknown plan-capabilities option: ' . $token);
            }
            if (!in_array($format, ['text', 'json'], true)) {
                throw new InvalidArgumentException('Unknown plan-capabilities format: ' . $format);
            }

            $capabilities = array_map(
                static fn (PlanCliApplication $application): PlanCapability => $application->capability(),
                $applications,
            );
            usort(
                $capabilities,
                static fn (PlanCapability $left, PlanCapability $right): int => $left->family <=> $right->family
                    ?: $left->kind <=> $right->kind,
            );

            if ($format === 'json') {
                echo json_encode([
                    'type' => 'plan_capabilities',
                    'capabilities' => array_map(
                        static fn (PlanCapability $capability): array => $capability->toArray(),
                        $capabilities,
                    ),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            echo "Governed plan contracts:\n";
            foreach ($capabilities as $capability) {
                echo sprintf(
                    "  %s/%s: %s -> %s@%s [%s]\n",
                    $capability->family,
                    $capability->kind,
                    $capability->command,
                    $capability->planType,
                    $capability->contractVersion,
                    $capability->semanticBackend,
                );
            }

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");

            return 1;
        }
    }
}
