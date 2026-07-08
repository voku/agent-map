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
            $absolutePath = $root . '/' . trim($path, '/');
            if ($path === '.') {
                $absolutePath = $root;
            }

            if (!is_dir($absolutePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                $absolute = $this->normalizePath($item->getPathname());
                if (!$item->isFile() || !str_ends_with($absolute, '.php')) {
                    continue;
                }

                $relative = ltrim(substr($absolute, strlen($root)), '/');
                if ($this->isExcluded($relative, $absolute, $patterns)) {
                    continue;
                }

                $files[$relative] = $relative;
            }
        }

        sort($files);

        return $files;
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
