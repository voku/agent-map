<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use InvalidArgumentException;
use voku\AgentMap\Plan\GovernedPlan;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanFileDeletion;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Plan\PlanStatus;

/** Versioned, read-only plan for removing one unused class together with its owned source file. */
final readonly class ClassRemovalPlan implements GovernedPlan
{
    public const PLAN_TYPE = 'class_removal_plan';
    public const CONTRACT_VERSION = '1.0';
    public const STATUS_SAFE = PlanStatus::SAFE;
    public const STATUS_REVIEW_REQUIRED = PlanStatus::REVIEW_REQUIRED;
    public const STATUS_BLOCKED = PlanStatus::BLOCKED;

    /**
     * @param list<PlanEdit> $edits kept empty: contract 1.0 removes one whole owned file
     * @param list<PlanFileDeletion> $deletions
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<PlanStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     * @param list<string> $notObservable
     */
    public function __construct(
        public string $status,
        public string $targetId,
        public PlanProvenance $provenance,
        public array $edits,
        public array $deletions,
        public array $blindSpots,
        public array $staleEvidence,
        public array $blockers,
        public array $notObservable,
    ) {
        PlanStatus::assertPublishable(self::PLAN_TYPE, $status, $edits, [], $deletions);
        if ($edits !== []) {
            throw new InvalidArgumentException('Class removal contract 1.0 deletes one owned file and cannot publish source edits.');
        }
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => self::PLAN_TYPE,
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'provenance' => $this->provenance->toArray(),
            'edits' => [],
            'deletions' => array_map(static fn (PlanFileDeletion $deletion): array => $deletion->toArray(), $this->deletions),
            'blind_spots' => array_map(static fn (PlanBlindSpot $spot): array => $spot->toArray(), $this->blindSpots),
            'stale_evidence' => array_map(static fn (PlanStaleEvidence $stale): array => $stale->toArray(), $this->staleEvidence),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];
    }
}
