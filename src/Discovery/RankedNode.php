<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class RankedNode
{
    public function __construct(
        public GraphNode $node,
        public int $score,
        public int $uncertainRelations,
    ) {
    }

    /**
     * @return array{node: array{id: string, kind: string, name: string, file: string, line_start: int, line_end: int}, score: int, uncertain_relations: int}
     */
    public function toArray(): array
    {
        return [
            'node' => $this->node->toArray(),
            'score' => $this->score,
            'uncertain_relations' => $this->uncertainRelations,
        ];
    }
}
