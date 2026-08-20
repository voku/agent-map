<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

/** Immutable evidence package for one same-namespace PHP class rename. */
final readonly class ClassRenamePlan
{
    public const STATUS_SAFE = 'safe';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param list<RenameEdit> $edits
     * @param list<RenameMove> $moves
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<string> $blockers
     * @param list<string> $notObservable
     */
    public function __construct(
        public string $status,
        public string $targetId,
        public string $originalFqn,
        public string $replacementFqn,
        public string $backend,
        public string $mapDigest,
        public array $edits,
        public array $moves,
        public array $blindSpots,
        public array $blockers,
        public array $notObservable,
    ) {
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'target_id' => $this->targetId,
            'original_fqn' => $this->originalFqn,
            'replacement_fqn' => $this->replacementFqn,
            'backend' => $this->backend,
            'map_digest' => $this->mapDigest,
            'edits' => array_map(static fn (RenameEdit $edit): array => $edit->toArray(), $this->edits),
            'moves' => array_map(static fn (RenameMove $move): array => $move->toArray(), $this->moves),
            'blind_spots' => array_map(static fn (RenameBlindSpot $blindSpot): array => $blindSpot->toArray(), $this->blindSpots),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];
    }
}
