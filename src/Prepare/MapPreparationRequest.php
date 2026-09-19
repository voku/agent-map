<?php

declare(strict_types=1);

namespace voku\AgentMap\Prepare;

use voku\AgentMap\MapArtifactPaths;

final readonly class MapPreparationRequest
{
    /**
     * @param list<string> $paths
     * @param list<string> $scanPaths
     * @param list<string> $excludes
     * @param 'auto'|'structural'|'phpstan' $backend
     * @param 'json'|'toon' $format
     */
    public function __construct(
        public string $root,
        public string $indexPath,
        public string $outputPath,
        public string $format,
        public array $paths,
        public bool $pathsProvided,
        public array $scanPaths,
        public bool $scanPathsProvided,
        public array $excludes,
        public bool $excludesProvided,
        public string $backend,
        public ?string $phpStanConfig,
        public ?string $phpStanMemoryLimit,
        public MapArtifactPaths $artifacts,
    ) {
    }
}
