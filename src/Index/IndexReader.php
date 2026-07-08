<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;

final readonly class IndexReader
{
    public function read(string $file): AgentMapIndex
    {
        if (!is_file($file)) {
            throw new RuntimeException('Index file not found: ' . $file);
        }

        $json = file_get_contents($file);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read index: ' . $file);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid index JSON: ' . $file);
        }

        return AgentMapIndex::fromArray($data);
    }
}
