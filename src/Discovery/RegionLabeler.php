<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\FileEntry;

final readonly class RegionLabeler
{
    /** @var list<string> */
    private const NOISE = [
        'app', 'application', 'impl', 'implementation', 'internal', 'lib', 'main',
        'php', 'src', 'test', 'tests', 'vendor',
    ];

    /**
     * @param non-empty-list<string> $files
     * @param array<string, FileEntry> $fileEntries
     */
    public function label(array $files, array $fileEntries): string
    {
        $namespaces = [];
        foreach ($files as $file) {
            $entry = $fileEntries[$file] ?? null;
            $namespace = $entry === null ? '' : trim($entry->namespace, '\\');
            if ($namespace !== '') {
                $namespaces[$namespace] = ($namespaces[$namespace] ?? 0) + 1;
            }
        }
        if ($namespaces !== []) {
            arsort($namespaces, SORT_NUMERIC);
            $namespace = (string) array_key_first($namespaces);
            if ($namespaces[$namespace] / count($files) >= 0.6) {
                $parts = explode('\\', $namespace);
                $candidate = (string) end($parts);
                if (!$this->isNoise($candidate)) {
                    return $candidate;
                }
            }
        }

        $directories = array_map(static fn (string $file): array => explode('/', dirname(str_replace('\\', '/', $file))), $files);
        $common = $this->commonPrefix($directories);
        for ($index = count($common) - 1; $index >= 0; --$index) {
            if (!$this->isNoise($common[$index])) {
                return $this->humanize($common[$index]);
            }
        }

        $scores = [];
        foreach ($directories as $segments) {
            $maximumDepth = max(1, count($segments) - 1);
            foreach ($segments as $depth => $segment) {
                if ($this->isNoise($segment)) {
                    continue;
                }
                $scores[$segment] = ($scores[$segment] ?? 0.0) + 1.0 + $depth / $maximumDepth;
            }
        }
        if ($scores !== []) {
            arsort($scores, SORT_NUMERIC);
            return $this->humanize((string) array_key_first($scores));
        }

        $tokens = [];
        foreach ($files as $file) {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            foreach (preg_split('/(?<=[a-z])(?=[A-Z])|[_-]+/', $stem) ?: [] as $token) {
                if (strlen($token) >= 3 && !$this->isNoise($token)) {
                    $tokens[$token] = ($tokens[$token] ?? 0) + 1;
                }
            }
        }
        if ($tokens !== []) {
            arsort($tokens, SORT_NUMERIC);
            return $this->humanize((string) array_key_first($tokens));
        }

        return 'Region';
    }

    /**
     * @param non-empty-list<non-empty-list<string>> $paths
     * @return list<string>
     */
    private function commonPrefix(array $paths): array
    {
        $prefix = $paths[0];
        foreach (array_slice($paths, 1) as $path) {
            $maximum = min(count($prefix), count($path));
            $length = 0;
            while ($length < $maximum && $prefix[$length] === $path[$length]) {
                ++$length;
            }
            $prefix = array_slice($prefix, 0, $length);
        }

        return $prefix;
    }

    public function isNoise(string $token): bool
    {
        return $token === '' || $token === '.' || in_array(strtolower($token), self::NOISE, true);
    }

    public function humanize(string $token): string
    {
        $token = (string) preg_replace('/[_-]+/', ' ', $token);
        return ucwords($token);
    }
}
