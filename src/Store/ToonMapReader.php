<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

use HelgeSverre\Toon\Toon;
use RuntimeException;

final readonly class ToonMapReader
{
    public function __construct(private MapIntegrityValidator $validator = new MapIntegrityValidator())
    {
    }

    public function read(string $directory): MapSnapshot
    {
        $manifest = $this->decode($directory . '/manifest.toon');
        if (($manifest['schema_version'] ?? null) !== '2.0') {
            throw new RuntimeException('Unsupported TOON map schema version.');
        }

        $artifacts = $manifest['artifacts'] ?? null;
        if (!is_array($artifacts)) {
            throw new RuntimeException('TOON map manifest has no artifacts map.');
        }

        $payloads = [];
        foreach (['files', 'symbols', 'relations', 'diagnostics'] as $name) {
            $metadata = $artifacts[$name] ?? null;
            if (!is_array($metadata) || !is_string($metadata['path'] ?? null) || !is_string($metadata['sha256'] ?? null)) {
                throw new RuntimeException('TOON map manifest has invalid metadata for ' . $name . '.');
            }

            $path = $directory . '/' . $metadata['path'];
            $content = file_get_contents($path);
            if (!is_string($content)) {
                throw new RuntimeException('Unable to read TOON map artifact: ' . $path);
            }

            $actualHash = 'sha256:' . hash('sha256', $content);
            if (!hash_equals($metadata['sha256'], $actualHash)) {
                throw new RuntimeException('TOON map artifact hash mismatch: ' . $name . '.');
            }

            $payload = Toon::decode($content);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid TOON map artifact: ' . $name . '.');
            }
            $this->validator->validateArtifact($name, $payload);
            $payloads[$name] = $payload;
        }

        return new MapSnapshot(
            $manifest,
            $payloads['files'],
            $payloads['symbols'],
            $payloads['relations'],
            $payloads['diagnostics'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read TOON map file: ' . $path);
        }

        $decoded = Toon::decode($content);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid TOON map file: ' . $path);
        }

        return $decoded;
    }
}
