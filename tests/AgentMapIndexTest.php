<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class AgentMapIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-index-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents($this->root . '/src/EvidenceValidator.php', "<?php\nfinal class EvidenceValidator {}\n");
        file_put_contents($this->root . '/tests/EvidenceValidatorTest.php', "<?php\nfinal class EvidenceValidatorTest {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSerializesAndReadsJsonAndToon(): void
    {
        foreach (['json', 'toon'] as $format) {
            $path = $this->root . '/map.' . $format;
            (new IndexWriter())->write($this->index(), $path, $format);
            $read = (new IndexReader())->read($path);

            self::assertSame('2.0', $read->schemaVersion);
            self::assertSame('src/EvidenceValidator.php', $read->files[0]->path);
            self::assertSame('User|null', $read->files[0]->symbols[0]->methods[0]->resolvedReturnType);
            self::assertCount(1, $read->relations);
            self::assertNotNull($read->fingerprint);
            self::assertSame('2.2.0', $read->fingerprint->phpStanVersion);
            self::assertSame('sha256:sources', $read->fingerprint->sourceDigest);
        }
    }

    public function testJsonAndToonRoundTripToSameModel(): void
    {
        (new IndexWriter())->write($this->index(), $this->root . '/map.json', 'json');
        (new IndexWriter())->write($this->index(), $this->root . '/map.toon', 'toon');

        self::assertSame(
            (new IndexReader())->read($this->root . '/map.json')->toArray(),
            (new IndexReader())->read($this->root . '/map.toon')->toArray(),
        );
    }

    public function testReaderFallsBackToJsonWhenToonExtensionContainsJson(): void
    {
        $path = $this->root . '/map.toon';
        (new IndexWriter())->write($this->index(), $path, 'json');

        self::assertSame('2.0', (new IndexReader())->read($path)->schemaVersion);
    }

    public function testReaderFallsBackToToonWhenJsonExtensionContainsToon(): void
    {
        $path = $this->root . '/map.json';
        (new IndexWriter())->write($this->index(), $path, 'toon');

        self::assertSame('2.0', (new IndexReader())->read($path)->schemaVersion);
    }

    public function testTypeLookupNormalizesLeadingBackslash(): void
    {
        self::assertNotNull($this->index()->symbolById('type:\Demo\EvidenceValidator'));
    }

    public function testLikelyTestFilesDoNotMatchProductionPathSubstrings(): void
    {
        $production = $this->index()->files[0];
        $contest = new FileEntry('src/Contest/Entry.php', 'sha256:none', 'Demo', [new SymbolEntry('class', 'Entry', 'Demo\Contest\Entry', 1, 1)]);
        $latest = new FileEntry('src/Latest/TestimonialService.php', 'sha256:none', 'Demo', [new SymbolEntry('class', 'TestimonialService', 'Demo\Latest\TestimonialService', 1, 1)]);
        $index = new AgentMapIndex('2.0', $this->root, 'test', [$production, $contest, $latest]);

        self::assertSame([], $index->likelyTestFiles($production));
    }

    public function testRelationIdentityIncludesReceiverAndResultTypes(): void
    {
        $first = RelationEntry::create('method:Demo\Caller::run', 'calls', ['method:Demo\Target::go'], 'src/Caller.php', 10, 10, 'phpstan_resolved', 'Demo\A', 'int');
        $second = RelationEntry::create('method:Demo\Caller::run', 'calls', ['method:Demo\Target::go'], 'src/Caller.php', 10, 10, 'phpstan_resolved', 'Demo\B', 'string');

        self::assertNotSame($first->id, $second->id);
    }

    public function testQueryFindsClassAndMethodIncludingNormalizedName(): void
    {
        self::assertSame('exact', $this->index()->query('EvidenceValidator')->matchType);
        $methodMatch = $this->index()->query('validate_agent_history_reference');
        self::assertSame('normalized', $methodMatch->matchType);
        self::assertSame('validateAgentHistoryReference', $methodMatch->files[0]->symbols[0]->methods[0]->name);
    }

    public function testResolveMethodRequiresQualifiedNameWhenAmbiguous(): void
    {
        file_put_contents($this->root . '/src/Other.php', "<?php\nfinal class Other {}\n");
        $other = new FileEntry(
            'src/Other.php',
            $this->hash('src/Other.php'),
            'Other',
            [new SymbolEntry('class', 'EvidenceValidator', 'Other\\EvidenceValidator', 1, 2, [new MethodEntry('validate', 'public', 2, 2)])],
        );
        $index = new AgentMapIndex('2.0', $this->root, 'test', [...$this->index()->files, $other]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambiguous');
        $index->resolveMethod('EvidenceValidator::validate');
    }

    public function testIncomingAndOutgoingRelationsWork(): void
    {
        $index = $this->index();
        self::assertCount(1, $index->incoming('method:Demo\\EvidenceValidator::validate', 'calls'));
        self::assertCount(1, $index->outgoing('method:Demo\\Caller::run', 'calls'));
    }

    public function testStaleDetectsModifiedAndMissingFilesByHash(): void
    {
        $index = $this->index();
        file_put_contents($this->root . '/src/EvidenceValidator.php', "<?php\n// changed\n");
        self::assertSame([['path' => 'src/EvidenceValidator.php', 'reason' => 'hash']], $index->staleEntries());

        unlink($this->root . '/src/EvidenceValidator.php');
        self::assertSame([['path' => 'src/EvidenceValidator.php', 'reason' => 'missing']], $index->staleEntries());
    }

    public function testLikelyTestFilesAndSummaryHelpers(): void
    {
        $index = $this->index();
        self::assertSame(['tests/EvidenceValidatorTest.php'], array_map(static fn (FileEntry $file): string => $file->path, $index->likelyTestFiles($index->files[0])));
        self::assertSame(2, $index->summaryCounts()['files_indexed']);
        self::assertSame(2, $index->methodCount());
    }

    private function index(): AgentMapIndex
    {
        $method = new MethodEntry(
            name: 'validate',
            visibility: 'public',
            lineStart: 2,
            lineEnd: 2,
            nativeReturnType: 'object|null',
            phpDocReturnType: 'T|null',
            resolvedReturnType: 'User|null',
            reconciliationStatus: 'semantic_enrichment',
        );
        $validator = new SymbolEntry(
            kind: 'class',
            name: 'EvidenceValidator',
            fqn: 'Demo\\EvidenceValidator',
            lineStart: 1,
            lineEnd: 2,
            methods: [$method, new MethodEntry('validateAgentHistoryReference', 'private', 2, 2)],
            reconciliationStatus: 'confirmed',
        );
        $production = new FileEntry('src/EvidenceValidator.php', $this->hash('src/EvidenceValidator.php'), 'Demo', [$validator]);
        $test = new FileEntry('tests/EvidenceValidatorTest.php', $this->hash('tests/EvidenceValidatorTest.php'), 'Demo\\Tests', [new SymbolEntry('class', 'EvidenceValidatorTest', 'Demo\\Tests\\EvidenceValidatorTest', 1, 2)]);
        $relation = RelationEntry::create(
            sourceId: 'method:Demo\\Caller::run',
            kind: 'calls',
            targetIds: ['method:Demo\\EvidenceValidator::validate'],
            file: 'src/Caller.php',
            lineStart: 10,
            lineEnd: 10,
            resolution: 'phpstan_resolved',
        );

        return new AgentMapIndex(
            '2.0',
            $this->root,
            'test',
            [$production, $test],
            [$relation],
            fingerprint: new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:sources'),
        );
    }

    private function hash(string $relative): string
    {
        $hash = hash_file('sha256', $this->root . '/' . $relative);
        self::assertIsString($hash);
        return 'sha256:' . $hash;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
