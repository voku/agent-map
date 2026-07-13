<?php

declare(strict_types=1);

namespace voku\AgentMap\IO;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final readonly class PhpFileFinder
{
    /** @var list<string> */
    private const DEFAULT_EXCLUDES = [
        '~(^|/)vendor(/|$)~',
        '~(^|/)\.git(/|$)~',
        '~(^|/)node_modules(/|$)~',
        '~(^|/)var/cache(/|$)~',
    ];

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     *
     * @return list<string>
     */
    public function find(string $root, array $paths, array $excludes = []): array
    {
        $root = $this->normalizeAbsoluteRoot($root);
        $patterns = [...self::DEFAULT_EXCLUDES, ...$excludes];
        $this->validateRegexes($patterns);

        $files = [];
        foreach ($paths === [] ? ['.'] : $paths as $path) {
            $absolutePath = $this->resolvePathWithinRoot($root, $path);
            if ($absolutePath === null) {
                continue;
            }

            if (is_file($absolutePath)) {
                $this->addPhpFile($files, $root, $absolutePath, $patterns);
                continue;
            }

            if (!is_dir($absolutePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $this->addPhpFile($files, $root, $item->getPathname(), $patterns);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $patterns
     */
    private function addPhpFile(array &$files, string $root, string $path, array $patterns): void
    {
        $real = realpath($path);
        if (!is_string($real)) {
            return;
        }

        $absolute = $this->normalizePath($real);
        if (!$this->isWithinRoot($root, $absolute)) {
            return;
        }

        if (!str_ends_with($absolute, '.php')) {
            return;
        }

        $relative = ltrim(substr($absolute, strlen($root)), '/');
        if ($this->isExcluded($relative, $absolute, $patterns)) {
            return;
        }

        $files[$relative] = $relative;
    }

    private function resolvePathWithinRoot(string $root, string $path): ?string
    {
        $candidate = $path === '.'
            ? $root
            : $root . '/' . trim(str_replace('\\', '/', $path), '/');
        $real = realpath($candidate);
        if (!is_string($real)) {
            return null;
        }

        $absolute = $this->normalizePath($real);

        return $this->isWithinRoot($root, $absolute) ? $absolute : null;
    }

    private function isWithinRoot(string $root, string $absolute): bool
    {
        return $absolute === $root || str_starts_with($absolute, $root . '/');
    }

    /**
     * @param list<string> $patterns
     */
    private function validateRegexes(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            set_error_handler(static fn (): bool => true);
            $valid = preg_match($pattern, '') !== false;
            restore_error_handler();
            if (!$valid) {
                throw new InvalidArgumentException('Invalid exclude regex: ' . $pattern);
            }
        }
    }

    /**
     * @param list<string> $patterns
     */
    private function isExcluded(string $relative, string $absolute, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $relative) === 1 || preg_match($pattern, $absolute) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeAbsoluteRoot(string $root): string
    {
        $real = realpath($root);
        if (!is_string($real) || !is_dir($real)) {
            throw new InvalidArgumentException('Root directory not found: ' . $root);
        }

        return $this->normalizePath($real);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
