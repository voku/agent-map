<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class GitChangeHistory
{
    public function __construct(private GitCommandRunner $git = new GitCommandRunner())
    {
    }

    /** @return list<list<string>> */
    public function commits(string $root, int $limit): array
    {
        $stdout = $this->git->run(realpath($root) ?: $root, [
            'log',
            '--no-merges',
            '--no-renames',
            '-n',
            (string) max(1, $limit),
            '--name-only',
            '--pretty=format:__AGENT_MAP_COMMIT__%H',
        ]);

        $commits = [];
        $current = [];
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '__AGENT_MAP_COMMIT__')) {
                if ($current !== []) {
                    $commits[] = $this->uniquePaths($current);
                }
                $current = [];
                continue;
            }
            if ($line !== '') {
                $current[] = str_replace('\\', '/', $line);
            }
        }
        if ($current !== []) {
            $commits[] = $this->uniquePaths($current);
        }

        return $commits;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function uniquePaths(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }
}
