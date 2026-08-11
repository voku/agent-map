<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use RuntimeException;

final readonly class GitRevisionInspector
{
    public function __construct(private GitCommandRunner $git = new GitCommandRunner())
    {
    }

    public function current(string $root): GitRevision
    {
        $root = realpath($root) ?: $root;
        $status = trim($this->git->run($root, ['status', '--porcelain', '--untracked-files=no']));
        if ($status !== '') {
            throw new RuntimeException(
                'Temporal snapshots require a clean tracked Git working tree so every observation can be rebuilt.',
            );
        }

        $commit = trim($this->git->run($root, ['rev-parse', 'HEAD']));
        $committedAt = trim($this->git->run($root, ['show', '-s', '--format=%cI', 'HEAD']));
        if ($commit === '' || $committedAt === '') {
            throw new RuntimeException('Unable to resolve Git revision metadata for temporal snapshot.');
        }

        return new GitRevision($commit, $committedAt);
    }
}
