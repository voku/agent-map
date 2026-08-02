<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

use RuntimeException;

final readonly class MapIntegrityValidator
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validateArtifact(string $name, array $payload): void
    {
        if (($payload['schema_version'] ?? null) !== '2.0') {
            throw new RuntimeException('Unsupported schema version in ' . $name . '.');
        }

        $listKey = match ($name) {
            'files' => 'files',
            'symbols' => 'symbols',
            'relations' => 'relations',
            'diagnostics' => 'diagnostics',
            default => throw new RuntimeException('Unknown map artifact: ' . $name),
        };

        if (!is_array($payload[$listKey] ?? null) || !array_is_list($payload[$listKey])) {
            throw new RuntimeException('Map artifact ' . $name . ' has no valid ' . $listKey . ' list.');
        }

        if ($name === 'files') {
            $this->assertUniqueStringField($payload[$listKey], 'path', $name);
        } elseif (in_array($name, ['symbols', 'relations', 'diagnostics'], true)) {
            $this->assertUniqueStringField($payload[$listKey], 'id', $name);
        }
    }

    /**
     * @param list<mixed> $rows
     */
    private function assertUniqueStringField(array $rows, string $field, string $artifact): void
    {
        $seen = [];
        foreach ($rows as $offset => $row) {
            if (!is_array($row) || !is_string($row[$field] ?? null) || $row[$field] === '') {
                throw new RuntimeException(sprintf('Invalid %s.%s at offset %d.', $artifact, $field, $offset));
            }

            if (isset($seen[$row[$field]])) {
                throw new RuntimeException(sprintf('Duplicate %s.%s: %s', $artifact, $field, $row[$field]));
            }

            $seen[$row[$field]] = true;
        }
    }
}
