<?php

declare(strict_types=1);

namespace voku\AgentMap\Search\Embedding;

use JsonException;

/**
 * Resolves the sqlite-vec binary shipped inside this package, the way PHPStan ships its turbo
 * extension: one directory per platform, picked at runtime.
 *
 * sqlite-vec is a SQLite extension rather than a PHP extension, so one binary per platform serves
 * every supported PHP version - no build matrix multiplied by PHP releases, no ZTS variants.
 *
 * Only the platforms this package is developed and run on are shipped. Everywhere else the resolver
 * returns null and the vector channel reports itself unavailable, which is the whole point of
 * treating it as optional: a missing binary must degrade search, never break it.
 *
 * The checksum is verified before the path is handed to a loader. A vendored binary that is loaded
 * into the process without checking what it is would be a supply-chain hole wearing a convenience
 * costume.
 */
final readonly class SqliteVecBinary
{
    /** An explicit path always wins, for a platform we do not ship or a locally built binary. */
    public const ENVIRONMENT_OVERRIDE = 'AGENT_MAP_SQLITE_VEC';

    public static function resolve(): ?string
    {
        $override = getenv(self::ENVIRONMENT_OVERRIDE);
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $platform = self::platform();
        if ($platform === null) {
            return null;
        }

        $manifest = self::manifest();
        $entry = $manifest['binaries'][$platform] ?? null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || !is_string($entry['sha256'] ?? null)) {
            return null;
        }

        $path = self::directory() . '/' . $entry['file'];
        if (!is_file($path)) {
            return null;
        }

        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($entry['sha256'], $actual)) {
            return null;
        }

        return $path;
    }

    public static function version(): ?string
    {
        $version = self::manifest()['version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * Deliberately coarse. glibc and musl need different binaries, and PHP cannot tell them apart
     * without guessing, so only the platforms in the manifest are claimed; anything else is "not
     * shipped" rather than "probably fine".
     */
    public static function platform(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        return match (php_uname('m')) {
            'x86_64', 'amd64'  => 'linux-gnu-x86_64',
            'aarch64', 'arm64' => 'linux-gnu-arm64',
            default            => null,
        };
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $file = self::directory() . '/manifest.json';
        if (!is_file($file)) {
            return [];
        }

        try {
            $decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 3) . '/sqlite-vec';
    }
}
