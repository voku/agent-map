<?php

declare(strict_types=1);

namespace voku\AgentMap\Evidence;

final readonly class DefinitionEvidence
{
    /**
     * @param 'answered'|'not_found'|'unavailable'|'error' $status
     * @param list<DefinitionLocation> $definitions
     * @param array<string, string> $toolchain
     * @param array{build_ms: float, materialize_ms: float, index_bytes: int}|array{} $metrics
     */
    public function __construct(
        public string $status,
        public string $provider,
        public string $capability,
        public array $definitions = [],
        public ?string $reason = null,
        public array $toolchain = [],
        public array $metrics = [],
    ) {
    }

    /**
     * @param list<DefinitionLocation> $definitions
     * @param array<string, string> $toolchain
     * @param array{build_ms: float, materialize_ms: float, index_bytes: int} $metrics
     */
    public static function answered(array $definitions, array $toolchain, array $metrics): self
    {
        return new self('answered', 'scip', 'definition', $definitions, null, $toolchain, $metrics);
    }

    /**
     * @param array<string, string> $toolchain
     * @param array{build_ms: float, materialize_ms: float, index_bytes: int} $metrics
     */
    public static function notFound(array $toolchain, array $metrics): self
    {
        return new self('not_found', 'scip', 'definition', [], null, $toolchain, $metrics);
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
     *   metrics: array{build_ms: float, materialize_ms: float, index_bytes: int}|array{},
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
            'metrics' => $this->metrics,
            'definitions' => array_map(
                static fn (DefinitionLocation $definition): array => $definition->toArray(),
                $this->definitions,
            ),
        ];
    }
}
