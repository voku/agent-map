<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use voku\AgentMap\MapArtifactPaths;

/**
 * Public agent-map CLI entry point.
 *
 * Temporal, discovery and structural/search commands remain focused
 * implementations. This class owns only the routing decision between them and
 * the optional artifact mount point supplied by an embedding application.
 */
final readonly class CliApplication
{
    private ?MapArtifactPaths $artifacts;

    public function __construct(
        private ?string $projectRoot = null,
        ?string $mapRoot = null,
    ) {
        $this->artifacts = $projectRoot === null && $mapRoot === null
            ? null
            : MapArtifactPaths::forProject($projectRoot ?? (getcwd() ?: '.'), $mapRoot);
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $temporal = new TemporalCliApplication(artifacts: $this->artifacts);
        if ($temporal->supports($argv)) {
            return $temporal->run($argv);
        }

        $discovery = new DiscoveryCliApplication(artifacts: $this->artifacts);
        if ($discovery->supports($argv)) {
            return $discovery->run($argv);
        }

        $status = (new AgentMapApplication(
            artifacts: $this->artifacts,
            defaultRoot: $this->projectRoot,
        ))->run($argv);

        if ($this->isGeneralHelp($argv)) {
            echo <<<'TEXT'

Artifact paths:
  --out, --index, and --database are relative to --root unless an absolute path is given.

TEXT;
        }
        if ($temporal->shouldAppendToGeneralHelp($argv)) {
            echo $temporal->helpOverview();
        }
        if ($discovery->shouldAppendToGeneralHelp($argv)) {
            echo $discovery->helpOverview();
        }

        return $status;
    }

    /** @param list<string> $argv */
    private function isGeneralHelp(array $argv): bool
    {
        $command = $argv[1] ?? null;
        $remaining = array_slice($argv, 2);

        return in_array($command, ['help', '--help', '-h'], true)
            || in_array('--help', $remaining, true)
            || in_array('-h', $remaining, true);
    }
}
