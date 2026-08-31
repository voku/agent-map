<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;

/**
 * The persisted map is a contract, so reading one this build does not understand has to fail.
 *
 * Silently accepting another schema major produces a map whose sections are simply absent, and an
 * absent section reads as "no callers" rather than as "unknown" everywhere downstream.
 */
final class MapSchemaCompatibilityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-schema-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testTheBuildWritesAndReadsItsOwnSchema(): void
    {
        $path = $this->root . '/map.json';
        (new IndexWriter())->write(
            new AgentMapIndex(AgentMapIndex::SCHEMA_VERSION, $this->root, 'test', [new FileEntry('src/Foo.php', 'sha256:x', '', [])]),
            $path,
            'json',
        );

        $map = (new IndexReader())->read($path);

        self::assertSame(AgentMapIndex::SCHEMA_VERSION, $map->schemaVersion);
        self::assertStringStartsWith(AgentMapIndex::SUPPORTED_SCHEMA_MAJOR . '.', AgentMapIndex::SCHEMA_VERSION);
    }

    public function testAPatchLevelOfTheSupportedMajorStillReads(): void
    {
        $path = $this->write(['schema_version' => AgentMapIndex::SUPPORTED_SCHEMA_MAJOR . '.7', 'root' => $this->root, 'backend' => 'test', 'files' => []]);

        $map = (new IndexReader())->read($path);

        self::assertSame(AgentMapIndex::SUPPORTED_SCHEMA_MAJOR . '.7', $map->schemaVersion);
    }

    public function testAnOlderSchemaMajorIsRejectedWithARebuildInstruction(): void
    {
        $path = $this->write(['schema_version' => '1.0', 'root' => $this->root, 'backend' => 'simple', 'files' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported agent map schema version 1.0');

        (new IndexReader())->read($path);
    }

    public function testANewerSchemaMajorIsRejectedRatherThanPartiallyRead(): void
    {
        $path = $this->write(['schema_version' => '3.0', 'root' => $this->root, 'backend' => 'test', 'files' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('this build reads ' . AgentMapIndex::SUPPORTED_SCHEMA_MAJOR . '.x');

        (new IndexReader())->read($path);
    }

    public function testAMapWithoutASchemaVersionIsRejected(): void
    {
        $path = $this->write(['root' => $this->root, 'backend' => 'simple', 'files' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no schema_version');

        (new IndexReader())->read($path);
    }

    /** @param array<string, mixed> $payload */
    private function write(array $payload): string
    {
        $path = $this->root . '/map.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }
}
