<?php

declare(strict_types=1);

namespace voku\AgentMap\Evidence;

final readonly class DefinitionCapabilityReport
{
    /**
     * @param 'php'|'javascript_typescript'|'python'|'mixed'|'unsupported' $route
     */
    public function __construct(
        public string $route,
        public DefinitionCapability $php,
        public DefinitionCapability $javascriptTypescript,
        public DefinitionCapability $python,
    ) {
    }

    public function routesToPolyglotDefinition(): bool
    {
        return in_array($this->route, ['javascript_typescript', 'python', 'mixed'], true);
    }

    /**
     * @return array{
     *   type: 'definition_capabilities',
     *   route: string,
     *   capabilities: array{
     *     php: array{language: string, provider: string, status: string, reason: ?string, missing_executables: list<string>, next_action: ?string},
     *     javascript_typescript: array{language: string, provider: string, status: string, reason: ?string, missing_executables: list<string>, next_action: ?string},
     *     python: array{language: string, provider: string, status: string, reason: ?string, missing_executables: list<string>, next_action: ?string}
     *   }
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => 'definition_capabilities',
            'route' => $this->route,
            'capabilities' => [
                'php' => $this->php->toArray(),
                'javascript_typescript' => $this->javascriptTypescript->toArray(),
                'python' => $this->python->toArray(),
            ],
        ];
    }
}
