<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

/** The Composer PSR-4 evidence that made one class relocation mechanically derivable. */
final readonly class ClassMoveAutoloadEvidence
{
    public function __construct(
        public string $manifestPath,
        public string $manifestSha256,
        public string $sourcePrefix,
        public string $sourceDirectory,
        public string $sourceSection,
        public string $destinationPrefix,
        public string $destinationDirectory,
        public string $destinationSection,
        public string $destinationPath,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'manifest_path' => $this->manifestPath,
            'manifest_sha256' => $this->manifestSha256,
            'source_prefix' => $this->sourcePrefix,
            'source_directory' => $this->sourceDirectory,
            'source_section' => $this->sourceSection,
            'destination_prefix' => $this->destinationPrefix,
            'destination_directory' => $this->destinationDirectory,
            'destination_section' => $this->destinationSection,
            'destination_path' => $this->destinationPath,
        ];
    }
}
