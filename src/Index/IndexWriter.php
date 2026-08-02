<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
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
        $content = match ($format) {
            'json' => $this->json($payload),
            'toon' => $this->toonEncoder->encode($payload),
            default => throw new RuntimeException('Unsupported index format: ' . $format),
        };

        $temporary = $file . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write temporary index: ' . $temporary);
        }
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish index: ' . $file);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        $json = json_encode($this->normalizer->normalize($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode index JSON.');
        }

        return $json . "\n";
    }
}
