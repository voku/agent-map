<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

use voku\AgentMap\Plan\GovernedPlan;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanMove;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;

/** Immutable evidence package for one deterministic PHP class namespace relocation. */
final readonly class ClassMovePlan implements GovernedPlan
{
    public const CONTRACT_VERSION = '1.0';
    public const STATUS_SAFE = 'safe';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param list<PlanEdit> $edits
     * @param list<PlanMove> $moves
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<PlanStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     * @param list<string> $notObservable
     */
    public function __construct(
        public string $status,
        public string $targetId,
        public string $sourceFqn,
        public string $destinationFqn,
        public PlanProvenance $provenance,
        public ?ClassMoveAutoloadEvidence $autoload,
        public array $edits,
        public array $moves,
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
            'type' => 'class_move_plan',
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'source_fqn' => $this->sourceFqn,
            'destination_fqn' => $this->destinationFqn,
            'provenance' => $this->provenance->toArray(),
            'autoload' => $this->autoload?->toArray(),
            'edits' => array_map(static fn (PlanEdit $edit): array => $edit->toArray(), $this->edits),
            'moves' => array_map(static fn (PlanMove $move): array => $move->toArray(), $this->moves),
            'blind_spots' => array_map(static fn (PlanBlindSpot $blindSpot): array => $blindSpot->toArray(), $this->blindSpots),
            'stale_evidence' => array_map(static fn (PlanStaleEvidence $stale): array => $stale->toArray(), $this->staleEvidence),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];
    }
}
