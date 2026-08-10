<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;

final readonly class GraphNodeCatalog
{
    /** @var array<string, GraphNode> */
    private array $nodes;

    public function __construct(AgentMapIndex $map)
    {
        $nodes = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                $nodes[$symbol->id()] = new GraphNode(
                    id: $symbol->id(),
                    kind: $symbol->kind,
                    name: $symbol->fqn,
                    file: $file->path,
                    lineStart: $symbol->lineStart,
                    lineEnd: $symbol->lineEnd,
                );
                foreach ($symbol->methods as $method) {
                    $id = $symbol->methodId($method);
                    $nodes[$id] = new GraphNode(
                        id: $id,
                        kind: 'method',
                        name: $symbol->fqn . '::' . $method->name,
                        file: $file->path,
                        lineStart: $method->lineStart,
                        lineEnd: $method->lineEnd,
                    );
                }
            }
        }

        ksort($nodes, SORT_STRING);
        $this->nodes = $nodes;
    }

    public function find(string $id): ?GraphNode
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return list<GraphNode> */
    public function all(?string $kind = null): array
    {
        if ($kind === null) {
            return array_values($this->nodes);
        }

        return array_values(array_filter(
            $this->nodes,
            static fn (GraphNode $node): bool => $node->kind === $kind,
        ));
    }
}
