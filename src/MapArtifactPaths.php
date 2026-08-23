<?php

declare(strict_types=1);

namespace voku\AgentMap;

/** The embedder chooses the root; agent-map owns every filename below it. */
final readonly class MapArtifactPaths
{
    private function __construct(
        private string $root,
        private string $projectRoot,
    ) {
    }

    public static function forProject(string $projectRoot, ?string $artifactRoot = null): self
    {
        $projectRoot = self::absolute($projectRoot);
        $root = $artifactRoot === null || trim($artifactRoot) === ''
            ? '.agent-map'
            : trim(str_replace('\\', '/', $artifactRoot));

        return new self(
            rtrim(self::isAbsolute($root) ? $root : $projectRoot . '/' . trim($root, '/'), '/'),
            $projectRoot,
        );
    }

    public function indexJson(): string
    {
        return $this->path('php-symbols.json');
    }

    public function indexToon(): string
    {
        return $this->path('php-symbols.toon');
    }

    public function searchDatabase(): string
    {
        return $this->path('search.sqlite');
    }

    public function historyDatabase(): string
    {
        return $this->path('history.sqlite');
    }

    public function structuralCache(): string
    {
        return $this->path('structural-cache.json');
    }

    public function phpStanCache(): string
    {
        return $this->path('phpstan-cache');
    }

    /** Resolve one user-supplied artifact path using the public --root contract. */
    public function projectPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (self::isAbsolute($path)) {
            return $path;
        }

        return $this->projectRoot . '/' . ltrim($path, '/');
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
