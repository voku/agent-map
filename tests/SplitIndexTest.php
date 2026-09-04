<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\DiagnosticEntry;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SemanticScope;
use voku\AgentMap\Index\SymbolEntry;

final class SplitIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-split-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                foreach (glob($file . '/*') ?: [] as $nested) {
                    unlink($nested);
                }
                rmdir($file);

                continue;
            }
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testWritingIndexProducesBothSymbolsAndRelationsFiles(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        $relationsFile = $this->root . '/php-relations.json';

        $map = $this->createMap();
        (new IndexWriter())->write($map, $indexFile, 'json');

        self::assertFileExists($indexFile);
        self::assertFileExists($relationsFile);

        $indexJson = json_decode((string) file_get_contents($indexFile), true);
        self::assertIsArray($indexJson);
        self::assertCount(1, $indexJson['files']);
        self::assertSame([], $indexJson['relations']);
        self::assertSame('php-relations.json', $indexJson['relations_file']);

        $relationsJson = json_decode((string) file_get_contents($relationsFile), true);
        self::assertIsArray($relationsJson);
        self::assertCount(2, $relationsJson['relations']);
        self::assertSame('/project', $relationsJson['root']);
    }

    public function testReadingWithoutRelationsDoesNotTouchCompanionFile(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        $map = $this->createMap();
        (new IndexWriter())->write($map, $indexFile, 'json');

        $read = (new IndexReader())->read($indexFile, false);
        self::assertCount(1, $read->files);
        self::assertSame([], $read->relations);

        $sectioned = (new IndexReader())->readSections($indexFile, ['files']);
        self::assertCount(1, $sectioned->files);
        self::assertSame([], $sectioned->relations);
    }

    public function testReadingWithRelationsLoadsBothFiles(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        $map = $this->createMap();
        (new IndexWriter())->write($map, $indexFile, 'json');

        $read = (new IndexReader())->read($indexFile, true);
        self::assertCount(1, $read->files);
        self::assertCount(2, $read->relations);
        self::assertSame('calls', $read->relations[0]->kind);
    }

    public function testMissingRelationsCompanionFailsInsteadOfReportingAnEmptyGraph(): void
    {
        // Copying only the symbols file produced an index that answered every
        // relation question with an empty graph, so diffing it against a complete
        // index reported all of the other side's relations as newly added.
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->createMap(), $indexFile, 'json');

        $partial = $this->root . '/partial';
        self::assertTrue(mkdir($partial, 0o775, true));
        self::assertTrue(copy($indexFile, $partial . '/php-symbols.json'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/relations companion/');
        (new IndexReader())->read($partial . '/php-symbols.json');
    }

    public function testRenamedSymbolsFileStillFindsTheCompanionItNames(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->createMap(), $indexFile, 'json');
        self::assertTrue(copy($indexFile, $this->root . '/before.json'));

        $read = (new IndexReader())->read($this->root . '/before.json');

        self::assertCount(2, $read->relations);
    }

    public function testToonFormatProducesCompanionToonFile(): void
    {
        $indexFile = $this->root . '/php-symbols.toon';
        $relationsFile = $this->root . '/php-relations.toon';

        $map = $this->createMap();
        (new IndexWriter())->write($map, $indexFile, 'toon');

        self::assertFileExists($indexFile);
        self::assertFileExists($relationsFile);

        $read = (new IndexReader())->read($indexFile);
        self::assertCount(1, $read->files);
        self::assertCount(2, $read->relations);
    }

    private function createMap(): AgentMapIndex
    {
        $symbol = new SymbolEntry('class', 'Service', 'App\\Service', 3, 20);

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: '/project',
            backend: 'simple-php-code-parser+phpstan',
            files: [new FileEntry('src/Service.php', 'sha256:abc', 'App', [$symbol])],
            relations: [
                RelationEntry::create('method:Service::run', 'calls', ['method:Helper::call'], 'src/Service.php', 10, 10, 'phpstan_resolved'),
                RelationEntry::create('class:Service', 'uses', ['trait:Loggable'], 'src/Service.php', 4, 4, 'structural_only'),
            ],
            diagnostics: [DiagnosticEntry::create('info', 'metric', 'all ok')],
            fingerprint: new AnalysisFingerprint(
                phpStanVersion: '2.2.0',
                phpStanConfigSha256: 'sha256:cfg',
                composerLockSha256: 'sha256:lock',
                sourceDigest: 'sha256:src',
                phpStanReference: 'ref',
                semanticScope: new SemanticScope(['src'], [], []),
            ),
        );
    }
}
