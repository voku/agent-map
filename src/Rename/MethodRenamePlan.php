<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

final readonly class MethodRenamePlan
{
    public const STATUS_SAFE = 'safe';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param list<string> $family
     * @param list<RenameEdit> $edits
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<string> $blockers
     * @param list<string> $notObservable
     */
    public function __construct(
        public string $status,
        public string $targetId,
        public string $originalName,
        public string $replacementName,
        public string $backend,
        public string $mapDigest,
        public array $family,
        public array $edits,
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
            'type' => 'method_rename_plan',
            'status' => $this->status,
            'target_id' => $this->targetId,
            'original_name' => $this->originalName,
            'replacement_name' => $this->replacementName,
            'backend' => $this->backend,
            'map_digest' => $this->mapDigest,
            'family' => $this->family,
            'edits' => array_map(static fn (RenameEdit $edit): array => $edit->toArray(), $this->edits),
            'blind_spots' => array_map(static fn (RenameBlindSpot $blindSpot): array => $blindSpot->toArray(), $this->blindSpots),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];
    }
}
