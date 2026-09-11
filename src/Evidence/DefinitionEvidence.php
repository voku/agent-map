<?php

declare(strict_types=1);

namespace voku\AgentMap\Evidence;

final readonly class DefinitionEvidence
{
    /**
     * @param 'answered'|'not_found'|'unavailable'|'error' $status
     * @param list<DefinitionLocation> $definitions
     * @param array<string, string> $toolchain
     */
    public function __construct(
        public string $status,
        public string $provider,
        public string $capability,
        public array $definitions = [],
        public ?string $reason = null,
        public array $toolchain = [],
    ) {
    }

    /**
     * @param list<DefinitionLocation> $definitions
     * @param array<string, string> $toolchain
     */
    public static function answered(array $definitions, array $toolchain): self
    {
        return new self('answered', 'scip', 'definition', $definitions, null, $toolchain);
    }

    /** @param array<string, string> $toolchain */
    public static function notFound(array $toolchain): self
    {
        return new self('not_found', 'scip', 'definition', [], null, $toolchain);
    }

    /** @param array<string, string> $toolchain */
    public static function unavailable(string $reason, array $toolchain = []): self
    {
        return new self('unavailable', 'scip', 'definition', [], $reason, $toolchain);
    }

    /** @param array<string, string> $toolchain */
    public static function error(string $reason, array $toolchain = []): self
    {
        return new self('error', 'scip', 'definition', [], $reason, $toolchain);
    }

    /**
     * @return array{
     *   status: 'answered'|'not_found'|'unavailable'|'error',
     *   provider: string,
     *   capability: string,
     *   reason: string|null,
     *   toolchain: array<string, string>,
     *   definitions: list<array{symbol_id: string, file: string, line_start: int, line_end: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'provider' => $this->provider,
            'capability' => $this->capability,
            'reason' => $this->reason,
            'toolchain' => $this->toolchain,
            'definitions' => array_map(
                static fn (DefinitionLocation $definition): array => $definition->toArray(),
                $this->definitions,
            ),
        ];
    }
}
