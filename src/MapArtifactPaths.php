<?php

declare(strict_types=1);

namespace voku\AgentMap;

/**
 * One owner for the files agent-map keeps below its artifact root.
 *
 * The embedding application chooses where that root is mounted. agent-map
 * alone decides what lives below it.
 */
final readonly class MapArtifactPaths
{
    public const string INDEX_JSON_FILENAME = 'php-symbols.json';

    public const string INDEX_TOON_FILENAME = 'php-symbols.toon';

    public const string SEARCH_DATABASE_FILENAME = 'search.sqlite';

    public const string HISTORY_DATABASE_FILENAME = 'history.sqlite';

    public const string STRUCTURAL_CACHE_FILENAME = 'structural-cache.json';

    public const string PHPSTAN_CACHE_DIRECTORY = 'phpstan-cache';

    private string $root;

    private function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public static function forProject(string $projectRoot, ?string $artifactRoot = null): self
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        if ($artifactRoot === null || trim($artifactRoot) === '') {
            return new self($projectRoot . '/.agent-map');
        }

        $artifactRoot = trim(str_replace('\\', '/', $artifactRoot));
        if (!self::isAbsolute($artifactRoot)) {
            $artifactRoot = $projectRoot . '/' . trim($artifactRoot, '/');
        }

        return new self($artifactRoot);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function indexJson(): string
    {
        return $this->root . '/' . self::INDEX_JSON_FILENAME;
    }

    public function indexToon(): string
    {
        return $this->root . '/' . self::INDEX_TOON_FILENAME;
    }

    public function searchDatabase(): string
    {
        return $this->root . '/' . self::SEARCH_DATABASE_FILENAME;
    }

    public function historyDatabase(): string
    {
        return $this->root . '/' . self::HISTORY_DATABASE_FILENAME;
    }

    public function structuralCache(): string
    {
        return $this->root . '/' . self::STRUCTURAL_CACHE_FILENAME;
    }

    public function phpStanCache(): string
    {
        return $this->root . '/' . self::PHPSTAN_CACHE_DIRECTORY;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
