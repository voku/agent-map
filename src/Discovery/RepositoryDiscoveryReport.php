<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class RepositoryDiscoveryReport
{
    /**
     * @param list<RankedNode> $entrypointCandidates
     * @param list<RankedNode> $callHubs
     * @param list<RankedNode> $orchestrators
     * @param list<RankedNode> $typeHubs
     * @param list<array{from: string, to: string, links: int, uncertain_links: int}> $namespaceCoupling
     * @param list<array{from: string, to: string, links: int, uncertain_links: int}> $directoryCoupling
     * @param list<array{from: string, to: string, links: int, uncertain_links: int}> $fileCoupling
     * @param array{total_relations: int, certain_relations: int, uncertain_relations: int, dynamic_relations: int, multiple_target_relations: int, diagnostics: int} $quality
     */
    public function __construct(
        public string $mapDigest,
        public ArchitectureMapReport $architecture,
        public array $entrypointCandidates,
        public array $callHubs,
        public array $orchestrators,
        public array $typeHubs,
        public array $namespaceCoupling,
        public array $directoryCoupling,
        public array $fileCoupling,
        public array $quality,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'map_digest' => $this->mapDigest,
            'architecture' => $this->architecture->toArray(),
            'entrypoint_candidates' => array_map(static fn (RankedNode $node): array => $node->toArray(), $this->entrypointCandidates),
            'call_hubs' => array_map(static fn (RankedNode $node): array => $node->toArray(), $this->callHubs),
            'orchestrators' => array_map(static fn (RankedNode $node): array => $node->toArray(), $this->orchestrators),
            'type_hubs' => array_map(static fn (RankedNode $node): array => $node->toArray(), $this->typeHubs),
            'namespace_coupling' => $this->namespaceCoupling,
            'directory_coupling' => $this->directoryCoupling,
            'file_coupling' => $this->fileCoupling,
            'quality' => $this->quality,
        ];
    }
}
