<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

use HelgeSverre\Toon\Toon;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

final readonly class ToonMapWriter
{
    public function __construct(
        private CanonicalToonEncoder $encoder = new CanonicalToonEncoder(),
        private MapIntegrityValidator $validator = new MapIntegrityValidator(),
    ) {
    }

    public function write(AgentMapIndex $index, string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create map directory: ' . $directory);
        }

        $encoded = [];
        foreach (MapArtifacts::fromIndex($index)->payloads() as $name => $payload) {
            $this->validator->validateArtifact($name, $payload);
            $content = $this->encoder->encode($payload);
            $decoded = Toon::decode($content);
            if (!is_array($decoded)) {
                throw new RuntimeException('Unable to decode generated TOON artifact: ' . $name);
            }

            $encoded[$name] = $content;
        }

        $artifactHashes = [];
        foreach ($encoded as $name => $content) {
            $artifactHashes[$name] = 'sha256:' . hash('sha256', $content);
        }

        $mapDigestInput = [];
        foreach (['files', 'symbols', 'relations', 'diagnostics'] as $name) {
            $mapDigestInput[] = $encoded[$name];
        }

        $manifest = [
            'schema_version' => '2.0',
            'map_digest' => 'sha256:' . hash('sha256', implode("\0", $mapDigestInput)),
            'engines' => [
                'structural' => [
                    'name' => 'voku/simple-php-code-parser',
                    'backend' => $index->backend,
                ],
                'semantic' => [
                    'name' => 'phpstan/phpstan',
                    'status' => 'pending',
                ],
            ],
            'artifacts' => array_map(
                static fn (string $hash, string $name): array => ['path' => $name . '.toon', 'sha256' => $hash],
                $artifactHashes,
                array_keys($artifactHashes),
            ),
        ];
        $manifestContent = $this->encoder->encode($manifest);

        $suffix = '.tmp-' . getmypid();
        foreach ($encoded as $name => $content) {
            $this->writeFile($directory . '/' . $name . '.toon' . $suffix, $content);
        }
        $this->writeFile($directory . '/manifest.toon' . $suffix, $manifestContent);

        foreach (array_keys($encoded) as $name) {
            $this->publish($directory . '/' . $name . '.toon' . $suffix, $directory . '/' . $name . '.toon');
        }
        $this->publish($directory . '/manifest.toon' . $suffix, $directory . '/manifest.toon');
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write TOON map artifact: ' . $path);
        }
    }

    private function publish(string $temporary, string $target): void
    {
        if (!rename($temporary, $target)) {
            throw new RuntimeException('Unable to publish TOON map artifact: ' . $target);
        }
    }
}
