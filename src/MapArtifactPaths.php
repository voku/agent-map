<?php

declare(strict_types=1);

namespace voku\AgentMap;

/** The embedder chooses the root; agent-map owns every filename below it. */
final readonly class MapArtifactPaths
{
    private const DEFAULT_ROOT = '.agent-map';
    private const INDEX_JSON = 'php-symbols.json';
    private const INDEX_TOON = 'php-symbols.toon';
    private const SEARCH_DATABASE = 'search.sqlite';
    private const HISTORY_DATABASE = 'history.sqlite';
    private const STRUCTURAL_CACHE = 'structural-cache.json';
    private const PHPSTAN_CACHE = 'phpstan-cache';

    private function __construct(private string $root)
    {
    }

    public static function forProject(string $projectRoot, ?string $artifactRoot = null): self
    {
        $projectRoot = self::absolute($projectRoot);
        $root = $artifactRoot === null || trim($artifactRoot) === ''
            ? $projectRoot . '/' . self::DEFAULT_ROOT
            : trim(str_replace('\\', '/', $artifactRoot));
        if (!self::isAbsolute($root)) {
            $root = $projectRoot . '/' . trim($root, '/');
        }

        return new self(rtrim($root, '/'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public function indexJson(): string
    {
        return $this->path(self::INDEX_JSON);
    }

    public function indexToon(): string
    {
        return $this->path(self::INDEX_TOON);
    }

    public function searchDatabase(): string
    {
        return $this->path(self::SEARCH_DATABASE);
    }

    public function historyDatabase(): string
    {
        return $this->path(self::HISTORY_DATABASE);
    }

    public function structuralCache(): string
    {
        return $this->path(self::STRUCTURAL_CACHE);
    }

    public function phpStanCache(): string
    {
        return $this->path(self::PHPSTAN_CACHE);
    }

    private function path(string $name): string
    {
        return $this->root . '/' . $name;
    }

    private static function absolute(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (self::isAbsolute($path)) {
            return rtrim($path, '/');
        }

        $cwd = rtrim(str_replace('\\', '/', getcwd() ?: '.'), '/');

        return $path === '.' ? $cwd : $cwd . '/' . trim($path, '/');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
