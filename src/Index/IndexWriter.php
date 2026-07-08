<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;

final readonly class IndexWriter
{
    public function write(AgentMapIndex $index, string $file): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create index directory: ' . $directory);
        }

        $json = json_encode($index->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode index JSON.');
        }

        if (file_put_contents($file, $json . "\n") === false) {
            throw new RuntimeException('Unable to write index: ' . $file);
        }
    }
}
