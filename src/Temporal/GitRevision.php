<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class GitRevision
{
    public function __construct(
        public string $commit,
        public string $committedAt,
    ) {
    }
}
