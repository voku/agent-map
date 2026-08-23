<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;
use voku\AgentMap\Rename\RenameProvenance;
use voku\AgentMap\Rename\RenameStaleEvidence;

/** Versioned, read-only plan for removing one provably unused private class constant. */
final readonly class ClassConstantRemovalPlan
{
    public const CONTRACT_VERSION = '1.0';
    public const STATUS_SAFE = 'safe';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param list<RenameEdit> $edits
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<RenameStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     * @param list<string> $notObservable
     */
    public function __construct(
        public string $status,
        public string $targetId,
        public RenameProvenance $provenance,
        public array $edits,
        public array $blindSpots,
        public array $staleEvidence,
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
            'type' => 'class_constant_removal_plan',
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'provenance' => $this->provenance->toArray(),
            'edits' => array_map(static fn (RenameEdit $edit): array => $edit->toArray(), $this->edits),
            'blind_spots' => array_map(static fn (RenameBlindSpot $spot): array => $spot->toArray(), $this->blindSpots),
            'stale_evidence' => array_map(static fn (RenameStaleEvidence $stale): array => $stale->toArray(), $this->staleEvidence),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];
    }
}
