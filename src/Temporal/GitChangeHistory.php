<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class GitChangeHistory
{
    public function __construct(private GitCommandRunner $git = new GitCommandRunner())
    {
    }

    /** @return list<array{revision: string, files: list<string>}> */
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
        $revision = null;
        $files = [];
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '__AGENT_MAP_COMMIT__')) {
                $this->append($commits, $revision, $files);
                $revision = substr($line, strlen('__AGENT_MAP_COMMIT__'));
                $files = [];
                continue;
            }
            if ($line !== '') {
                $files[] = str_replace('\\', '/', $line);
            }
        }
        $this->append($commits, $revision, $files);

        return $commits;
    }

    /**
     * @param list<array{revision: string, files: list<string>}> $commits
     * @param list<string> $files
     */
    private function append(array &$commits, ?string $revision, array $files): void
    {
        if ($revision === null || $revision === '' || $files === []) {
            return;
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);
        $commits[] = ['revision' => $revision, 'files' => $files];
    }
}
