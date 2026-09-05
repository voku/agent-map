<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

use voku\AgentMap\Index\ResolvedMethod;
use voku\AgentMap\Inspect\LocalSemanticFrame;

final readonly class EditContextPlan
{
    /**
     * @param list<CodeSlice> $slices
     * @param list<ContextBlindSpot> $blindSpots
     * @param list<OmittedContext> $omitted
     */
    public function __construct(
        public string $requestedTarget,
        public ResolvedMethod $resolvedTarget,
        public EditContextPolicy $policy,
        public array $slices,
        public array $blindSpots,
        public array $omitted,
        public string $mapDigest,
        public int $sourceBytes,
        public ?LocalSemanticFrame $localSemantics = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => 'edit_context',
            'target' => [
                'requested' => $this->requestedTarget,
                'resolved' => $this->resolvedTarget->owner->fqn . '::' . $this->resolvedTarget->method->name,
                'symbol_id' => $this->resolvedTarget->id,
                'file' => $this->resolvedTarget->file->path,
                'line_start' => $this->resolvedTarget->method->lineStart,
                'line_end' => $this->resolvedTarget->method->lineEnd,
                'native_return_type' => $this->resolvedTarget->method->nativeReturnType,
                'phpdoc_return_type' => $this->resolvedTarget->method->phpDocReturnType,
                'resolved_return_type' => $this->resolvedTarget->method->resolvedReturnType,
            ],
            'map_digest' => $this->mapDigest,
            'source_bytes' => $this->sourceBytes,
            'slices' => array_map(static fn (CodeSlice $slice): array => $slice->toArray(), $this->slices),
            'blind_spots' => array_map(static fn (ContextBlindSpot $blindSpot): array => $blindSpot->toArray(), $this->blindSpots),
            'omitted' => array_map(static fn (OmittedContext $omitted): array => $omitted->toArray(), $this->omitted),
            'local_semantics' => $this->localSemantics?->toArray(),
        ];
    }
}
