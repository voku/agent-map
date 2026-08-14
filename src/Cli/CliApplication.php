<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use voku\AgentMap\MapArtifactPaths;

/**
 * Public agent-map CLI entry point.
 *
 * Temporal, discovery and structural/search commands remain separate focused
 * implementations. This class owns the one routing decision between them and,
 * when embedded, the one artifact-root default shared by all of them.
 */
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
        $resolved = $this->resolveDefaults($argv);
        $temporal = new TemporalCliApplication();
        if ($temporal->supports($resolved)) {
            return $temporal->run($resolved);
        }

        $discovery = new DiscoveryCliApplication();
        if ($discovery->supports($resolved)) {
            return $discovery->run($resolved);
        }

        $root = $this->effectiveProjectRoot($resolved);
        $artifacts = MapArtifactPaths::forProject($root, $this->mapRoot);
        $status = (new AgentMapApplication(mapRoot: $artifacts->root()))->run($resolved);
        if ($temporal->shouldAppendToGeneralHelp($resolved)) {
            echo $temporal->helpOverview();
        }
        if ($discovery->shouldAppendToGeneralHelp($resolved)) {
            echo $discovery->helpOverview();
        }

        return $status;
    }

    /** @param list<string> $argv
     *  @return list<string>
     */
    private function resolveDefaults(array $argv): array
    {
        $command = $argv[1] ?? 'help';
        if (in_array($command, ['help', '--help', '-h', ''], true)) {
            return $argv;
        }

        $root = $this->effectiveProjectRoot($argv);
        $artifacts = MapArtifactPaths::forProject($root, $this->mapRoot);

        if ($command === 'history') {
            return $this->resolveHistoryDefaults($argv, $artifacts);
        }

        if (($command === 'build' || $command === 'refresh') && !$this->hasOption($argv, 'root')) {
            $argv[] = '--root=' . $root;
        }

        if (($command === 'build' || $command === 'refresh') && !$this->hasOption($argv, 'out')) {
            $argv[] = '--out=' . ($this->optionValue($argv, 'format') === 'toon' ? $artifacts->indexToon() : $artifacts->indexJson());
        }

        if ($command !== 'build' && !$this->hasOption($argv, 'index')) {
            $argv[] = '--index=' . $artifacts->indexJson();
        }

        if (in_array($command, ['search-index', 'search'], true) && !$this->hasOption($argv, 'database')) {
            $argv[] = '--database=' . $artifacts->searchDatabase();
        }

        return $argv;
    }

    /** @param list<string> $argv
     *  @return list<string>
     */
    private function resolveHistoryDefaults(array $argv, MapArtifactPaths $artifacts): array
    {
        $subcommand = $argv[2] ?? 'help';
        if (in_array($subcommand, ['coupling', 'claims'], true)) {
            if (!$this->hasOption($argv, 'index')) {
                $argv[] = '--index=' . $artifacts->indexJson();
            }
            if (!$this->hasOption($argv, 'root')) {
                $argv[] = '--root=' . $this->effectiveProjectRoot($argv);
            }
        }

        if ($subcommand === 'observe' && !$this->hasOption($argv, 'index')) {
            $argv[] = '--index=' . $artifacts->indexJson();
        }

        if (in_array($subcommand, ['observe', 'show'], true) && !$this->hasOption($argv, 'database')) {
            $argv[] = '--database=' . $artifacts->historyDatabase();
        }

        return $argv;
    }

    /** @param list<string> $argv */
    private function effectiveProjectRoot(array $argv): string
    {
        return $this->optionValue($argv, 'root')
            ?? $this->projectRoot
            ?? (getcwd() ?: '.');
    }

    /** @param list<string> $tokens */
    private function hasOption(array $tokens, string $name): bool
    {
        foreach ($tokens as $token) {
            if ($token === '--' . $name || str_starts_with($token, '--' . $name . '=')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tokens */
    private function optionValue(array $tokens, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (str_starts_with($tokens[$i], $prefix)) {
                $value = substr($tokens[$i], strlen($prefix));

                return $value !== '' ? $value : null;
            }
            if ($tokens[$i] !== '--' . $name) {
                continue;
            }

            $value = $tokens[$i + 1] ?? null;

            return is_string($value) && $value !== '' && !str_starts_with($value, '--') ? $value : null;
        }

        return null;
    }
}
