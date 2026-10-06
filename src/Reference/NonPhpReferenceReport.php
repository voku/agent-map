<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference;

final readonly class NonPhpReferenceReport
{
    /** @param list<NonPhpReference> $references */
    public function __construct(
        public array $references,
        public int $total,
        public int $scannedFiles,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /** @return array{references: list<array<string, mixed>>, total: int, truncated: bool, scanned_files: int} */
    public function toArray(): array
    {
        return [
            'references' => array_map(static fn (NonPhpReference $reference): array => $reference->toArray(), $this->references),
            'total' => $this->total,
            'truncated' => $this->total > count($this->references),
            'scanned_files' => $this->scannedFiles,
        ];
    }
}
