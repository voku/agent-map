<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

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

        $temporal = new TemporalCliApplication(artifacts: $artifacts);
        if ($temporal->supports($argv)) {
            return $temporal->run($argv);
        }

        $rename = new RenameCliApplication(artifacts: $artifacts);
        if ($rename->supports($argv)) {
            return $rename->run($argv);
        }

        $discovery = new DiscoveryCliApplication(artifacts: $artifacts);
        if ($discovery->supports($argv)) {
            return $discovery->run($argv);
        }

        $status = (new AgentMapApplication(artifacts: $artifacts, defaultRoot: $this->projectRoot))->run($argv);
        $rest = array_slice($argv, 2);
        if (in_array($argv[1] ?? null, ['help', '--help', '-h'], true) || in_array('--help', $rest, true) || in_array('-h', $rest, true)) {
            echo "\nArtifact paths:\n  --out, --index, and --database are relative to --root unless an absolute path is given.\n\n";
        }
        if ($temporal->shouldAppendToGeneralHelp($argv)) {
            echo $temporal->helpOverview();
        }
        if ($rename->shouldAppendToGeneralHelp($argv)) {
            echo $rename->helpOverview();
        }
        if ($discovery->shouldAppendToGeneralHelp($argv)) {
            echo $discovery->helpOverview();
        }

        return $status;
    }
}
