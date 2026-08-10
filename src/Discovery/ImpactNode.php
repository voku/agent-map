<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class ImpactNode
{
    /**
     * @param non-empty-list<string> $relationKinds
     * @param non-empty-list<string> $evidenceIds
     */
    public function __construct(
        public GraphNode $node,
        public int $depth,
        public array $relationKinds,
        public array $evidenceIds,
        public bool $uncertain,
    ) {
    }

    /**
     * @return array{
     *   node: array{id: string, kind: string, name: string, file: string, line_start: int, line_end: int},
     *   depth: int,
     *   relation_kinds: non-empty-list<string>,
     *   evidence_ids: non-empty-list<string>,
     *   uncertain: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'node' => $this->node->toArray(),
            'depth' => $this->depth,
            'relation_kinds' => $this->relationKinds,
            'evidence_ids' => $this->evidenceIds,
            'uncertain' => $this->uncertain,
        ];
    }
}
