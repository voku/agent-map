<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

/**
 * The source scope PHPStan received when a semantic map was built.
 *
 * This deliberately records the user-facing paths and exclude rules instead of
 * the expanded PHP file list. PHPStan can then retain its own dependency-aware
 * result cache on a later refresh.
 */
final readonly class SemanticScope
{
    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     * @param list<string> $scanDirectories
     */
    public function __construct(
        public array $paths,
        public array $excludes,
        public array $scanDirectories,
    ) {
    }

    /** @return array{paths: list<string>, excludes: list<string>, scan_directories: list<string>, identity_sha256: string} */
    public function toArray(): array
    {
        return [
            'paths' => $this->paths,
            'excludes' => $this->excludes,
            'scan_directories' => $this->scanDirectories,
            'identity_sha256' => $this->identitySha256(),
        ];
    }

    /** @param array{paths?: mixed, excludes?: mixed, scan_directories?: mixed} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            paths: self::stringList($data['paths'] ?? []),
            excludes: self::stringList($data['excludes'] ?? []),
            scanDirectories: self::stringList($data['scan_directories'] ?? []),
        );
    }

    public function identitySha256(): string
    {
        return 'sha256:' . hash('sha256', (string) json_encode([
            'paths' => $this->paths,
            'excludes' => $this->excludes,
            'scan_directories' => $this->scanDirectories,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string>|array<mixed> $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $result[] = $value;
            }
        }

        return $result;
    }
}
