<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use JsonException;
use voku\AgentMap\MapArtifactPaths;

/**
 * Build-time record of a published map's digest, bound to the exact artifact bytes.
 *
 * `AgentMapIndex::mapDigest()` hashes the whole canonical map, which on a real project
 * means serialising tens of megabytes again for every process that reads the map, even
 * though the writer computed the same value moments after publishing it. The marker
 * keeps that value next to the artifacts together with a hash of the symbols and
 * relations files as written.
 *
 * A reader may use the recorded digest only when both artifact hashes match the bytes
 * it actually decoded. Anything else - an older map without a marker, a hand-edited or
 * copied artifact, a symbols/relations pair from different builds, a torn publish or an
 * unreadable marker - returns null, and the caller computes the digest as before. The
 * marker never changes the digest's value, only whether it has to be recomputed.
 *
 * The byte hash is xxh128: the marker guards against accidental mismatch, not against
 * a writer who can already change the artifacts and therefore any digest derived from
 * them, so a cryptographic hash would add cost without adding protection.
 *
 * @internal
 */
final readonly class MapDigestMarker
{
    public const SCHEMA_VERSION = 1;

    private const BYTE_HASH = 'xxh128';

    /**
     * Records the digest of the artifacts currently published at `$indexFile`.
     *
     * Call it after both artifacts are renamed into place and while holding the writer
     * lock, with the digest of the map decoded from those artifacts (not of the in-memory
     * index that was serialised). Failure is not fatal: without a marker readers simply
     * recompute the digest.
     */
    public function publish(string $indexFile, string $mapDigest): void
    {
        $markerFile = MapArtifactPaths::mapDigestFor($indexFile);
        $symbolsHash = @hash_file(self::BYTE_HASH, $indexFile);
        $relationsHash = @hash_file(self::BYTE_HASH, MapArtifactPaths::relationsFileFor($indexFile));
        if (!is_string($symbolsHash) || !is_string($relationsHash)) {
            @unlink($markerFile);

            return;
        }

        $content = json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'map_digest' => $mapDigest,
            'byte_hash' => self::BYTE_HASH,
            'symbols' => $symbolsHash,
            'relations' => $relationsHash,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        $temporary = $markerFile . '.tmp-' . getmypid();
        if (@file_put_contents($temporary, $content) === false || !@rename($temporary, $markerFile)) {
            @unlink($temporary);
            // Never leave a previous build's marker behind a failed publish.
            @unlink($markerFile);
        }
    }

    public function discard(string $indexFile): void
    {
        $markerFile = MapArtifactPaths::mapDigestFor($indexFile);
        if (is_file($markerFile)) {
            @unlink($markerFile);
        }
    }

    /**
     * Returns the recorded digest when the marker vouches for exactly these bytes.
     */
    public function verifiedDigest(string $indexFile, string $symbolsBytes, string $relationsBytes): ?string
    {
        $markerFile = MapArtifactPaths::mapDigestFor($indexFile);
        if (!is_file($markerFile)) {
            return null;
        }
        $content = @file_get_contents($markerFile);
        if (!is_string($content)) {
            return null;
        }

        try {
            $data = json_decode($content, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)
            || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($data['byte_hash'] ?? null) !== self::BYTE_HASH
            || !is_string($data['symbols'] ?? null)
            || !is_string($data['relations'] ?? null)
            || !is_string($data['map_digest'] ?? null)
            || preg_match('/\Asha256:[0-9a-f]{64}\z/', $data['map_digest']) !== 1
        ) {
            return null;
        }

        if (!hash_equals($data['symbols'], hash(self::BYTE_HASH, $symbolsBytes))
            || !hash_equals($data['relations'], hash(self::BYTE_HASH, $relationsBytes))
        ) {
            return null;
        }

        return $data['map_digest'];
    }
}
