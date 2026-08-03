<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use HelgeSverre\Toon\Exceptions\DecodeException;
use HelgeSverre\Toon\Toon;
use JsonException;
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

        $toonFirst = str_ends_with(strtolower($file), '.toon');
        $data = $toonFirst ? $this->decodeToon($content) : $this->decodeJson($content);
        if ($data === null) {
            // File extensions are useful hints, not a second map schema. Try
            // the other serializer so renamed or extension-free indexes still
            // load without making callers choose a different reader.
            $data = $toonFirst ? $this->decodeJson($content) : $this->decodeToon($content);
        }
        if ($data === null) {
            throw new RuntimeException('Invalid agent-map index: ' . $file);
        }

        return AgentMapIndex::fromArray($data);
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed>|null */
    private function decodeToon(string $content): ?array
    {
        try {
            $decoded = Toon::decode($content);
        } catch (DecodeException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
