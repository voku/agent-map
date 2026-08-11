<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use voku\AgentMap\Discovery\ArchitectureMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;

final readonly class EntityObservationBuilder
{
    public function __construct(private DeclarationSignature $signatures = new DeclarationSignature())
    {
    }

    /** @return list<EntityObservation> */
    public function build(AgentMapIndex $map): array
    {
        $architecture = (new ArchitectureMapBuilder())->build($map);
        $outgoing = [];
        $incoming = [];
        $callsOut = [];
        $callsIn = [];

        foreach ($map->relations as $relation) {
            foreach ($relation->targetIds as $targetId) {
                $outgoing[$relation->sourceId][$targetId] = true;
                $incoming[$targetId][$relation->sourceId] = true;
                if ($relation->kind === 'calls') {
                    $callsOut[$relation->sourceId][$targetId] = true;
                    $callsIn[$targetId][$relation->sourceId] = true;
                }
            }
        }

        $observations = [];
        foreach ($map->files as $file) {
            $region = $architecture->regionForFile($file->path);
            foreach ($file->symbols as $symbol) {
                $observations[] = $this->observation(
                    entityType: $symbol->kind,
                    entityId: $symbol->id(),
                    filePath: $file->path,
                    signature: $this->signatures->symbol($symbol),
                    regionId: $region?->id,
                    regionLabel: $region?->label,
                    outgoing: $outgoing,
                    incoming: $incoming,
                    callsOut: $callsOut,
                    callsIn: $callsIn,
                );
                foreach ($symbol->methods as $method) {
                    $methodId = $symbol->methodId($method);
                    $observations[] = $this->observation(
                        entityType: 'method',
                        entityId: $methodId,
                        filePath: $file->path,
                        signature: $this->signatures->method($method),
                        regionId: $region?->id,
                        regionLabel: $region?->label,
                        outgoing: $outgoing,
                        incoming: $incoming,
                        callsOut: $callsOut,
                        callsIn: $callsIn,
                    );
                }
            }
        }

        usort($observations, static fn (EntityObservation $left, EntityObservation $right): int => $left->entityId <=> $right->entityId);

        return $observations;
    }

    /**
     * @param array<string, array<string, true>> $outgoing
     * @param array<string, array<string, true>> $incoming
     * @param array<string, array<string, true>> $callsOut
     * @param array<string, array<string, true>> $callsIn
     */
    private function observation(
        string $entityType,
        string $entityId,
        string $filePath,
        string $signature,
        ?string $regionId,
        ?string $regionLabel,
        array $outgoing,
        array $incoming,
        array $callsOut,
        array $callsIn,
    ): EntityObservation {
        return new EntityObservation(
            entityType: $entityType,
            entityId: $entityId,
            filePath: $filePath,
            signature: $signature,
            callers: count($callsIn[$entityId] ?? []),
            callees: count($callsOut[$entityId] ?? []),
            dependents: count($incoming[$entityId] ?? []),
            dependencies: count($outgoing[$entityId] ?? []),
            regionId: $regionId,
            regionLabel: $regionLabel,
        );
    }
}
