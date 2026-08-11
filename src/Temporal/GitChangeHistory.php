<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use RuntimeException;

final readonly class GitChangeHistory
{
    /** @return list<list<string>> */
    public function commits(string $root, int $limit): array
    {
        $root = realpath($root) ?: $root;
        $command = [
            'git',
            '-C',
            $root,
            'log',
            '--no-merges',
            '--no-renames',
            '-n',
            (string) max(1, $limit),
            '--name-only',
            '--pretty=format:__AGENT_MAP_COMMIT__%H',
        ];

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start git history process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Unable to read git history.' . (is_string($stderr) && trim($stderr) !== '' ? ' ' . trim($stderr) : ''));
        }

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
