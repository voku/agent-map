<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use RuntimeException;

final readonly class GitCommandRunner
{
    /** @param list<string> $arguments */
    public function run(string $root, array $arguments): string
    {
        $command = ['git', '-C', $root, ...$arguments];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start git process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !is_string($stdout)) {
            throw new RuntimeException(
                'Git command failed.' . (is_string($stderr) && trim($stderr) !== '' ? ' ' . trim($stderr) : ''),
            );
        }

        return $stdout;
    }
}
