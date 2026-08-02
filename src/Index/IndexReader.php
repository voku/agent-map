<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use HelgeSverre\Toon\Toon;
use RuntimeException;

final readonly class IndexReader
{
    public function read(string $file): AgentMapIndex
    {
        if (!is_file($file)) {
            throw new RuntimeException('Index file not found: ' . $file);
        }
        $content = file_get_contents($file);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read index: ' . $file);
        }

        $data = str_ends_with(strtolower($file), '.toon')
            ? Toon::decode($content)
            : json_decode($content, true);
        if (!is_array($data)) {
            // Extension-free files are occasionally useful in tooling. Try the
            // other decoder before admitting defeat.
            $data = str_ends_with(strtolower($file), '.toon')
                ? json_decode($content, true)
                : Toon::decode($content);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Invalid agent-map index: ' . $file);
        }

        return AgentMapIndex::fromArray($data);
    }
}
