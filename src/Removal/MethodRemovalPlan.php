<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use voku\AgentMap\Plan\GovernedPlan;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Reference\NonPhpReferenceReport;

/** Versioned, read-only plan for removing one unused private method. */
final readonly class MethodRemovalPlan implements GovernedPlan
{
    public const PLAN_TYPE = 'method_removal_plan';
    public const CONTRACT_VERSION = '1.0';
    public const STATUS_SAFE = PlanStatus::SAFE;
    public const STATUS_REVIEW_REQUIRED = PlanStatus::REVIEW_REQUIRED;
    public const STATUS_BLOCKED = PlanStatus::BLOCKED;

    /**
     * @param list<PlanEdit> $edits
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
        public array $blindSpots,
        public array $staleEvidence,
        public array $blockers,
        public array $notObservable,
        public ?NonPhpReferenceReport $nonPhpReferences = null,
    ) {
        PlanStatus::assertPublishable(self::PLAN_TYPE, $status, $edits);
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'type' => self::PLAN_TYPE,
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'provenance' => $this->provenance->toArray(),
            'edits' => array_map(static fn (PlanEdit $edit): array => $edit->toArray(), $this->edits),
            'blind_spots' => array_map(static fn (PlanBlindSpot $spot): array => $spot->toArray(), $this->blindSpots),
            'stale_evidence' => array_map(static fn (PlanStaleEvidence $stale): array => $stale->toArray(), $this->staleEvidence),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];

        // Residue evidence for a later cleanup stage; it never changes the plan status or its edits.
        if ($this->nonPhpReferences !== null && !$this->nonPhpReferences->isEmpty()) {
            $data['non_php_references'] = $this->nonPhpReferences->toArray();
        }

        return $data;
    }
}
