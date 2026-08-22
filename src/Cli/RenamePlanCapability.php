<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

/** Machine-readable description of one governed rename-plan CLI capability. */
final readonly class RenamePlanCapability
{
    public function __construct(
        public string $kind,
        public string $command,
        public string $planType,
        public string $contractVersion,
        public string $semanticBackend = 'phpstan',
    ) {
    }

    /** @return array{kind: string, command: string, plan_type: string, contract_version: string, semantic_backend: string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'command' => $this->command,
            'plan_type' => $this->planType,
            'contract_version' => $this->contractVersion,
            'semantic_backend' => $this->semanticBackend,
        ];
    }
}
