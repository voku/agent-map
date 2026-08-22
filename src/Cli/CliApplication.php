<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use InvalidArgumentException;
use Throwable;
use voku\AgentMap\MapArtifactPaths;

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

        $renameApplications = $this->renameApplications($artifacts);
        if (($argv[1] ?? null) === 'rename-capabilities'
            || (($argv[1] ?? null) === 'help' && ($argv[2] ?? null) === 'rename-capabilities')) {
            return $this->renameCapabilities($argv, $renameApplications);
        }

        $temporal = new TemporalCliApplication(artifacts: $artifacts);
        if ($temporal->supports($argv)) {
            return $temporal->run($argv);
        }

        foreach ($renameApplications as $renameApplication) {
            if ($renameApplication->supports($argv)) {
                return $renameApplication->run($argv);
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
            echo "Rename capability discovery:\n  rename-capabilities List registered governed rename-plan contracts\n\n";
        }
        if ($temporal->shouldAppendToGeneralHelp($argv)) {
            echo $temporal->helpOverview();
        }
        foreach ($renameApplications as $renameApplication) {
            if ($renameApplication->shouldAppendToGeneralHelp($argv)) {
                echo $renameApplication->helpOverview();
            }
        }
        if ($discovery->shouldAppendToGeneralHelp($argv)) {
            echo $discovery->helpOverview();
        }

        return $status;
    }

    /**
     * @return list<RenamePlanCliApplication>
     */
    private function renameApplications(?MapArtifactPaths $artifacts): array
    {
        return [
            new ClassRenameCliApplication(artifacts: $artifacts),
            new FunctionRenameCliApplication(artifacts: $artifacts),
            new RenameCliApplication(artifacts: $artifacts),
        ];
    }

    /**
     * @param list<string> $argv
     * @param list<RenamePlanCliApplication> $applications
     */
    private function renameCapabilities(array $argv, array $applications): int
    {
        try {
            if (($argv[1] ?? null) === 'help' || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
                echo <<<'TEXT'
Usage: agent-map rename-capabilities [--format text|json]

List the governed rename-plan capabilities registered at the public CLI routing boundary.
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

                throw new InvalidArgumentException('Unknown rename-capabilities option: ' . $token);
            }
            if (!in_array($format, ['text', 'json'], true)) {
                throw new InvalidArgumentException('Unknown rename-capabilities format: ' . $format);
            }

            $capabilities = array_map(
                static fn (RenamePlanCliApplication $application): RenamePlanCapability => $application->capability(),
                $applications,
            );
            usort(
                $capabilities,
                static fn (RenamePlanCapability $left, RenamePlanCapability $right): int => $left->kind <=> $right->kind,
            );

            if ($format === 'json') {
                echo json_encode([
                    'type' => 'rename_capabilities',
                    'capabilities' => array_map(
                        static fn (RenamePlanCapability $capability): array => $capability->toArray(),
                        $capabilities,
                    ),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            echo "Governed rename-plan capabilities:\n";
            foreach ($capabilities as $capability) {
                echo sprintf(
                    "  %s: %s -> %s@%s [%s]\n",
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
