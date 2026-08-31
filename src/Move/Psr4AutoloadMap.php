<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

use JsonException;
use RuntimeException;

/**
 * Read-only Composer autoload evidence for one analysed project.
 *
 * A namespace move is only mechanical when the file path it implies can be derived instead of
 * guessed, so this reads the project's declared PSR-4 prefixes and keeps the manifest identity
 * as provenance. It never rewrites Composer configuration.
 */
final readonly class Psr4AutoloadMap
{
    /**
     * @param list<Psr4Mapping> $prefixes ordered by descending prefix length
     * @param list<string> $classmap
     * @param list<string> $files
     */
    private function __construct(
        public string $manifestPath,
        public string $manifestSha256,
        public array $prefixes,
        public array $classmap,
        public array $files,
    ) {
    }

    public static function forProject(string $root): self
    {
        $manifestPath = rtrim(str_replace('\\', '/', $root), '/') . '/composer.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Cannot prove a PSR-4 destination without a Composer manifest at composer.json.');
        }

        $raw = file_get_contents($manifestPath);
        if (!is_string($raw)) {
            throw new RuntimeException('Cannot read the Composer manifest at composer.json.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Composer manifest is not valid JSON: ' . $exception->getMessage());
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Composer manifest does not decode to an object.');
        }

        $prefixes = [];
        $classmap = [];
        $files = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $block = $decoded[$section] ?? null;
            if (!is_array($block)) {
                continue;
            }
            foreach (is_array($block['psr-4'] ?? null) ? $block['psr-4'] : [] as $prefix => $directories) {
                if (!is_string($prefix)) {
                    continue;
                }
                foreach (is_array($directories) ? $directories : [$directories] as $directory) {
                    if (!is_string($directory)) {
                        continue;
                    }
                    $prefixes[] = Psr4Mapping::fromDeclaration($prefix, $directory, $section);
                }
            }
            foreach (is_array($block['classmap'] ?? null) ? $block['classmap'] : [] as $entry) {
                if (is_string($entry)) {
                    $classmap[] = self::normalizeEntry($entry);
                }
            }
            foreach (is_array($block['files'] ?? null) ? $block['files'] : [] as $entry) {
                if (is_string($entry)) {
                    $files[] = self::normalizeEntry($entry);
                }
            }
        }

        usort($prefixes, static fn (Psr4Mapping $left, Psr4Mapping $right): int => strlen($right->prefix) <=> strlen($left->prefix)
            ?: $left->prefix <=> $right->prefix
            ?: $left->directory <=> $right->directory);

        return new self(
            manifestPath: 'composer.json',
            manifestSha256: 'sha256:' . hash('sha256', $raw),
            prefixes: $prefixes,
            classmap: $classmap,
            files: $files,
        );
    }

    /**
     * Every declared mapping that covers the identity, longest prefix first.
     *
     * @return list<Psr4Mapping>
     */
    public function candidates(string $fqn): array
    {
        $candidates = [];
        foreach ($this->prefixes as $mapping) {
            if ($mapping->covers($fqn)) {
                $candidates[] = $mapping;
            }
        }

        return $candidates;
    }

    /**
     * The mappings that already explain the indexed location of a declaration.
     *
     * @return list<Psr4Mapping>
     */
    public function declarationCandidates(string $fqn, string $path): array
    {
        $matches = [];
        foreach ($this->candidates($fqn) as $mapping) {
            if ($mapping->pathFor($fqn) === $path) {
                $matches[] = $mapping;
            }
        }

        return $matches;
    }

    public function isCoveredByClassmap(string $path): bool
    {
        foreach ($this->classmap as $entry) {
            if ($entry === '' || $path === $entry || str_starts_with($path, $entry . '/')) {
                return true;
            }
        }

        return in_array($path, $this->files, true);
    }

    /**
     * Normalizes a classmap/files entry for prefix comparison only.
     *
     * A leading slash is deliberately kept: an absolute entry must fail to match a project-relative
     * path rather than being trimmed into one that matches something else.
     */
    private static function normalizeEntry(string $entry): string
    {
        $entry = str_replace('\\', '/', $entry);
        if (str_starts_with($entry, './')) {
            $entry = substr($entry, 2);
        }

        return rtrim($entry, '/');
    }
}
