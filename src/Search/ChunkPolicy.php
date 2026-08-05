<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

/**
 * How code is cut into searchable chunks.
 *
 * The version is part of every chunk id and every content fingerprint. Changing what a chunk
 * contains therefore invalidates the derived index on purpose: a stale chunk that keeps its old
 * fingerprint would silently reuse an embedding computed for different text.
 */
final readonly class ChunkPolicy
{
    public const VERSION = 1;

    /** Methods longer than this are indexed by their head; the full body stays reachable via the map. */
    public const MAX_BODY_LINES = 400;
}
