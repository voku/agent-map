<?php

declare(strict_types=1);

namespace voku\AgentMap\Evidence;

final readonly class DefinitionCapability
{
    /**
     * @param 'php'|'javascript_typescript'|'python' $language
     * @param 'php-map'|'scip' $provider
     * @param 'operational'|'unavailable' $status
     */
    public function __construct(
        public string $language,
        public string $provider,
        public string $status,
        public ?string $reason = null,
    ) {
    }

    /** @return array{language: string, provider: string, status: string, reason: ?string} */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'provider' => $this->provider,
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }
}
