<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class ScopeSelection
{
    /**
     * @param 'found'|'ambiguous'|'not_found' $status
     * @param list<string> $candidates
     */
    public function __construct(
        public ?ScopeTarget $target,
        public string $status,
        public array $candidates = [],
    ) {
    }
}
