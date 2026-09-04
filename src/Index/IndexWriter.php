<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Store\CanonicalArrayNormalizer;
use voku\AgentMap\Store\CanonicalToonEncoder;

final readonly class IndexWriter
{
    public function __construct(
        private CanonicalArrayNormalizer $normalizer = new CanonicalArrayNormalizer(),
        private CanonicalToonEncoder $toonEncoder = new CanonicalToonEncoder(),
    ) {
    }

    public function write(AgentMapIndex $index, string $file, ?string $format = null): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create index directory: ' . $directory);
        }

        $format ??= str_ends_with(strtolower($file), '.toon') ? 'toon' : 'json';
        $payload = $index->toArray();

        $relationsFile = MapArtifactPaths::relationsFileFor($file);
        $relationsPayload = [
            'schema_version' => $index->schemaVersion,
            'root' => $index->root,
            'backend' => $index->backend,
            'relations' => $payload['relations'] ?? [],
        ];
        if ($index->fingerprint !== null) {
            $relationsPayload['fingerprint'] = $payload['fingerprint'] ?? null;
        }

        $payload['relations'] = [];
        $payload['relations_file'] = basename($relationsFile);

        $temporary = $file . '.tmp-' . getmypid();
        $temporaryRelations = $relationsFile . '.tmp-' . getmypid();

        $this->writePayload($payload, $temporary, $format);
        $this->writePayload($relationsPayload, $temporaryRelations, $format);

        if (!rename($temporary, $file)) {
            @unlink($temporary);
            @unlink($temporaryRelations);
            throw new RuntimeException('Unable to publish index: ' . $file);
        }

        if (!rename($temporaryRelations, $relationsFile)) {
            @unlink($temporaryRelations);
            throw new RuntimeException('Unable to publish relations index: ' . $relationsFile);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writePayload(array $payload, string $temporary, string $format): void
    {
        match ($format) {
            'json' => $this->writeJson($payload, $temporary),
            'toon' => $this->writeString($this->toonEncoder->encode($payload), $temporary),
            default => throw new RuntimeException('Unsupported index format: ' . $format),
        };
    }

    /**
     * Encodes one list element at a time: a whole-map JSON string doubles the peak memory of a
     * large index, which is exactly where builds used to die.
     *
     * Every top-level section starts on its own line, and the closing brace gets one too. The result
     * is still ordinary JSON, but a reader that only needs `files` can skip the relation list - by
     * far the largest section - without decoding it. See IndexReader::readSections().
     *
     * @param array<string, mixed> $payload
     */
    private function writeJson(array $payload, string $temporary): void
    {
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to write temporary index: ' . $temporary);
        }

        try {
            /** @var array<string, mixed> $normalized */
            $normalized = $this->normalizer->normalize($payload);

            $this->put($handle, '{', $temporary);
            $first = true;
            foreach ($normalized as $key => $value) {
                $this->put($handle, ($first ? '' : ",\n") . $this->encode($key) . ':', $temporary);
                $first = false;

                if (!is_array($value) || !array_is_list($value)) {
                    $this->put($handle, $this->encode($value), $temporary);
                    continue;
                }

                $this->put($handle, '[', $temporary);
                $firstItem = true;
                foreach ($value as $item) {
                    $this->put($handle, ($firstItem ? '' : ',') . $this->encode($item), $temporary);
                    $firstItem = false;
                }
                $this->put($handle, ']', $temporary);
            }
            $this->put($handle, "\n}\n", $temporary);
        } finally {
            fclose($handle);
        }
    }

    private function writeString(string $content, string $temporary): void
    {
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write temporary index: ' . $temporary);
        }
    }

    /** @param resource $handle */
    private function put($handle, string $content, string $temporary): void
    {
        if (fwrite($handle, $content) === false) {
            throw new RuntimeException('Unable to write temporary index: ' . $temporary);
        }
    }

    private function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode index JSON.');
        }

        return $json;
    }
}
