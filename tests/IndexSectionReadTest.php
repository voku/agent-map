<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\DiagnosticEntry;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class IndexSectionReadTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/agent-map-sections-' . bin2hex(random_bytes(6)) . '.json';
        (new IndexWriter())->write($this->index(), $this->file, 'json');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testWrittenIndexRemainsValidJson(): void
    {
        $decoded = json_decode((string) file_get_contents($this->file), true);

        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['files']);
        self::assertCount(1, $decoded['relations']);
    }

    public function testRequestedSectionIsDecodedAndTheRestIsSkipped(): void
    {
        $index = (new IndexReader())->readSections($this->file, ['files']);

        self::assertSame('/tmp/project', $index->root);
        self::assertSame('2.0', $index->schemaVersion);
        self::assertCount(1, $index->files);
        self::assertSame('src/Alpha.php', $index->files[0]->path);
        self::assertSame([], $index->relations);
        self::assertSame([], $index->diagnostics);
    }

    public function testAllSectionsMatchAFullRead(): void
    {
        $partial = (new IndexReader())->readSections($this->file, ['files', 'relations', 'diagnostics']);
        $full = (new IndexReader())->read($this->file);

        self::assertEquals($full, $partial);
    }

    public function testAnIndexWithoutTheSectionLayoutStillReads(): void
    {
        $compact = (string) json_encode(json_decode((string) file_get_contents($this->file), true));
        file_put_contents($this->file, $compact);

        $index = (new IndexReader())->readSections($this->file, ['files']);

        self::assertCount(1, $index->files);
        // The fallback is a full read, so the skipped sections come back populated.
        self::assertCount(1, $index->relations);
    }

    private function index(): AgentMapIndex
    {
        $symbol = new SymbolEntry(kind: 'class', name: 'Alpha', fqn: 'Alpha', lineStart: 3, lineEnd: 9);

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: '/tmp/project',
            backend: 'simple-php-code-parser+phpstan',
            files: [new FileEntry('src/Alpha.php', 'sha256:abc', '', [$symbol])],
            relations: [RelationEntry::create('class:Alpha', 'declares_method', ['method:Alpha::run'], 'src/Alpha.php', 5, 8, 'structural_only')],
            diagnostics: [DiagnosticEntry::create('warning', 'phpstan_finding', 'something to report')],
        );
    }
}
