<?php

declare(strict_types=1);

namespace voku\AgentMap\Plan;

/**
 * Machine-readable identity of one governed plan contract.
 *
 * A host has to know which mechanical refactorings this agent-map can prove before it asks for one,
 * and which map backend each needs. Discovering that from a typed registry is the supported path;
 * probing CLI help text is not.
 */
final readonly class PlanCapability
{
    public const FAMILY_RENAME = 'rename';
    public const FAMILY_REMOVAL = 'removal';
    public const FAMILY_MOVE = 'move';

    public function __construct(
        public string $family,
        public string $kind,
        public string $command,
        public string $planType,
        public string $contractVersion,
        public string $semanticBackend = 'phpstan',
    ) {
    }

    /** @return array{family: string, kind: string, command: string, plan_type: string, contract_version: string, semantic_backend: string} */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'kind' => $this->kind,
            'command' => $this->command,
            'plan_type' => $this->planType,
            'contract_version' => $this->contractVersion,
            'semantic_backend' => $this->semanticBackend,
        ];
    }
}
