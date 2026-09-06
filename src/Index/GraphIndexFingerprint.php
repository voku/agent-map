<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentMap\MapArtifactPaths;

final readonly class GraphIndexFingerprint
{
    public function forIndexFile(string $indexFile): string
    {
        $context = hash_init('sha256');
        foreach ([$indexFile, MapArtifactPaths::relationsFileFor($indexFile)] as $artifact) {
            if (!is_file($artifact)) {
                throw new RuntimeException('Canonical map artifact not found: ' . $artifact);
            }

            hash_update($context, basename($artifact) . "\0");
            if (!hash_update_file($context, $artifact)) {
                throw new RuntimeException('Unable to hash canonical map artifact: ' . $artifact);
            }
            hash_update($context, "\0");
        }

        return 'sha256:' . hash_final($context);
    }
}
