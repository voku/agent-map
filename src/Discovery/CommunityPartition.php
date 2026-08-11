<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class CommunityPartition
{
    /**
     * @param list<list<string>> $communities
     * @param array<string, int> $assignment
     */
    public function __construct(
        public array $communities,
        public array $assignment,
    ) {
    }
}
