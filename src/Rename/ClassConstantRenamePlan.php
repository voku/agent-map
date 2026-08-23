<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

/** Versioned read-only plan for one class-constant rename. */
final readonly class ClassConstantRenamePlan
{
    public const CONTRACT_VERSION = '1.0';
    public const STATUS_SAFE = 'safe';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Carries exact edit evidence and keeps stale evidence separate from semantic blockers.
     *
     * @param list<RenameEdit> $edits
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<RenameStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     * @param list<string> $notObservable
     */
    public function __construct(
        public string $status,
        public string $targetId,
        public string $ownerFqn,
        public string $originalName,
        public string $replacementName,
        public RenameProvenance $provenance,
        public array $edits,
        public array $blindSpots,
        public array $staleEvidence,
        public array $blockers,
        public array $notObservable,
    ) {
    }

    /** Returns whether consumers must reject the plan without applying any edit. */
    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    /** @return array<string, mixed> Stable machine-readable contract payload. */
    public function toArray(): array
    {
        return [
            'type' => 'class_constant_rename_plan',
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'owner_fqn' => $this->ownerFqn,
            'original_name' => $this->originalName,
            'replacement_name' => $this->replacementName,
            'provenance' => $this->provenance->toArray(),
            'edits' => array_map(static fn (RenameEdit $edit): array => $edit->toArray(), $this->edits),
            'blind_spots' => array_map(static fn (RenameBlindSpot $spot): array => $spot->toArray(), $this->blindSpots),
            'stale_evidence' => array_map(static fn (RenameStaleEvidence $stale): array => $stale->toArray(), $this->staleEvidence),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];
    }
}
