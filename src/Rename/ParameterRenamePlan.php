<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use voku\AgentMap\Plan\GovernedPlan;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Plan\PlanStatus;

final readonly class ParameterRenamePlan implements GovernedPlan
{
    public const PLAN_TYPE = 'parameter_rename_plan';
    public const CONTRACT_VERSION = '1.0';
    public const STATUS_SAFE = PlanStatus::SAFE;
    public const STATUS_REVIEW_REQUIRED = PlanStatus::REVIEW_REQUIRED;
    public const STATUS_BLOCKED = PlanStatus::BLOCKED;

    /**
     * @param list<string> $family
     * @param list<PlanEdit> $edits
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<PlanStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     * @param list<string> $notObservable
     */
    public function __construct(
        public string $status,
        public string $targetId,
        public string $originalName,
        public string $replacementName,
        public int $parameterIndex,
        public PlanProvenance $provenance,
        public array $family,
        public array $edits,
        public array $blindSpots,
        public array $staleEvidence,
        public array $blockers,
        public array $notObservable,
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
        return [
            'type' => self::PLAN_TYPE,
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'original_name' => $this->originalName,
            'replacement_name' => $this->replacementName,
            'parameter_index' => $this->parameterIndex,
            'provenance' => $this->provenance->toArray(),
            'family' => $this->family,
            'edits' => array_map(static fn (PlanEdit $edit): array => $edit->toArray(), $this->edits),
            'blind_spots' => array_map(static fn (PlanBlindSpot $blindSpot): array => $blindSpot->toArray(), $this->blindSpots),
            'stale_evidence' => array_map(static fn (PlanStaleEvidence $stale): array => $stale->toArray(), $this->staleEvidence),
            'blockers' => $this->blockers,
            'not_observable' => $this->notObservable,
        ];
    }
}
