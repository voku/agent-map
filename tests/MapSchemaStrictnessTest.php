<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

final class MapSchemaStrictnessTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function malformedSchemaVersions(): iterable
    {
        yield 'numeric major' => [2];
        yield 'bare major string' => ['2'];
    }

    #[DataProvider('malformedSchemaVersions')]
    public function testIncompleteSchemaVersionDoesNotMasqueradeAsSupportedMajor(mixed $schemaVersion): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema_version must be a major.minor string');

        AgentMapIndex::fromArray([
            'schema_version' => $schemaVersion,
            'root' => '/tmp/project',
            'backend' => 'test',
            'files' => [],
        ]);
    }
}
