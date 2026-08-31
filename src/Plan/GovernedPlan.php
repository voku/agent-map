<?php

declare(strict_types=1);

namespace voku\AgentMap\Plan;

/**
 * The behaviour every governed, read-only agent-map plan shares.
 *
 * The concrete plans deliberately stay separate types: a class move and a constant removal carry
 * genuinely different evidence, and collapsing them into one shape would mean inventing fields that
 * do not apply. What has to be identical is how a mutation host reads them - one status vocabulary,
 * one machine projection, and one rule that a blocked plan publishes no applicable mutation.
 */
interface GovernedPlan
{
    /** A blocked plan publishes no edits or moves, whatever its concrete evidence would have been. */
    public function isBlocked(): bool;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
