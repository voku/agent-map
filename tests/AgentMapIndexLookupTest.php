<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\LocalBindingEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use WeakReference;

/**
 * The memoised lookups must answer exactly what the full scans they replaced answered:
 * same entries, same order, first match on duplicates.
 */
final class AgentMapIndexLookupTest extends TestCase
{
    public function testRelationLookupsMatchFullScansInOrder(): void
    {
        $index = $this->index();
        $ids = ['method:App\A::run', 'method:App\B::help', 'method:App\B::other', 'class:App\B', 'method:App\Missing::x'];

        foreach ($ids as $id) {
            foreach ([null, 'calls', 'references_type'] as $kind) {
                self::assertSame($this->scanOutgoing($index, $id, $kind), $index->outgoing($id, $kind), 'outgoing ' . $id);
                self::assertSame($this->scanIncoming($index, $id, $kind), $index->incoming($id, $kind), 'incoming ' . $id);
            }
        }
    }

    public function testRelationListingTheSameTargetTwiceIsOneIncomingRelation(): void
    {
        $incoming = $this->index()->incoming('method:App\B::help', 'calls');

        self::assertCount(2, $incoming);
        self::assertSame(['L3', 'L7'], array_map(static fn (RelationEntry $r): string => 'L' . $r->lineStart, $incoming));
    }

    public function testSymbolAndMethodLookupsKeepFirstMatch(): void
    {
        $index = $this->index();

        $method = $index->resolvedMethodById('method:App\B::help');
        self::assertNotNull($method);
        self::assertSame('src/B.php', $method->file->path, 'first declaration wins over the duplicate in src/B2.php');
        self::assertSame('method:App\B::help', $method->id);
        self::assertNull($index->resolvedMethodById('method:App\B::nope'));

        self::assertSame('src/B.php', $index->symbolById('class:App\B')['file']->path ?? null);
        self::assertSame('src/B.php', $index->symbolById('type:\App\B')['file']->path ?? null);
        self::assertSame('src/B.php', $index->symbolById('type:App\B')['file']->path ?? null);
        self::assertNull($index->symbolById('class:App\Nope'));
        self::assertNull($index->symbolById('type:App\Nope'));

        self::assertSame('src/A.php', $index->file('./src/A.php')?->path);
        self::assertNull($index->file('src/Nope.php'));
    }

    public function testBindingLookupGroupsByOwnerInOrder(): void
    {
        $index = $this->index();

        self::assertSame(['b1', 'b3'], array_map(static fn (LocalBindingEntry $b): string => $b->id, $index->bindingsFor('method:App\A::run')));
        self::assertSame([], $index->bindingsFor('method:App\Nope::x'));
        self::assertSame([], $index->exitsFor('method:App\A::run'));
    }

    public function testMapDigestIsStableAndMatchesAnEqualIndex(): void
    {
        $index = $this->index();
        $digest = $index->mapDigest();

        self::assertSame($digest, $index->mapDigest());
        self::assertSame($digest, $this->index()->mapDigest(), 'an equal, separately built index has the same digest');
        self::assertNotSame($digest, $this->index(extraRelation: true)->mapDigest(), 'different content, different digest');
    }

    public function testLookupTablesDoNotKeepTheIndexAlive(): void
    {
        $index = $this->index();
        $index->incoming('method:App\B::help');
        $index->mapDigest();
        $reference = WeakReference::create($index);
        unset($index);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    private function index(bool $extraRelation = false): AgentMapIndex
    {
        $a = new SymbolEntry('class', 'A', 'App\A', 1, 20, [new MethodEntry('run', 'public', 2, 10)]);
        $b = new SymbolEntry('class', 'B', 'App\B', 1, 20, [new MethodEntry('help', 'public', 2, 5), new MethodEntry('other', 'public', 6, 9)]);
        $bDuplicate = new SymbolEntry('class', 'B', '\App\B', 1, 20, [new MethodEntry('help', 'public', 2, 5)]);
        $relations = [
            RelationEntry::create('method:App\A::run', 'calls', ['method:App\B::help', 'method:App\B::help'], 'src/A.php', 3, 3, 'phpstan_resolved'),
            RelationEntry::create('method:App\A::run', 'references_type', ['class:App\B'], 'src/A.php', 4, 4, 'phpstan_resolved'),
            RelationEntry::create('method:App\B::other', 'calls', ['method:App\B::help'], 'src/B.php', 7, 7, 'phpstan_resolved'),
            RelationEntry::create('method:App\A::run', 'calls', ['method:App\B::other', 'method:App\Missing::x'], 'src/A.php', 8, 8, 'multiple_targets'),
        ];
        if ($extraRelation) {
            $relations[] = RelationEntry::create('method:App\B::help', 'calls', ['method:App\A::run'], 'src/B.php', 3, 3, 'phpstan_resolved');
        }

        return new AgentMapIndex(
            schemaVersion: AgentMapIndex::SCHEMA_VERSION,
            root: '/tmp/project',
            backend: 'structural',
            files: [
                new FileEntry('src/A.php', 'sha256:a', 'App', [$a]),
                new FileEntry('src/B.php', 'sha256:b', 'App', [$b]),
                new FileEntry('src/B2.php', 'sha256:b2', 'App', [$bDuplicate]),
            ],
            relations: $relations,
            localBindings: [
                new LocalBindingEntry('b1', 'method:App\A::run', '$x', 'src/A.php', 3, 3, 'int', 'literal'),
                new LocalBindingEntry('b2', 'method:App\B::help', '$y', 'src/B.php', 3, 3, 'int', 'literal'),
                new LocalBindingEntry('b3', 'method:App\A::run', '$z', 'src/A.php', 5, 5, 'int', 'literal'),
            ],
        );
    }

    /** @return list<RelationEntry> */
    private function scanOutgoing(AgentMapIndex $index, string $id, ?string $kind): array
    {
        return array_values(array_filter(
            $index->relations,
            static fn (RelationEntry $r): bool => $r->sourceId === $id && ($kind === null || $r->kind === $kind),
        ));
    }

    /** @return list<RelationEntry> */
    private function scanIncoming(AgentMapIndex $index, string $id, ?string $kind): array
    {
        return array_values(array_filter(
            $index->relations,
            static fn (RelationEntry $r): bool => ($kind === null || $r->kind === $kind) && in_array($id, $r->targetIds, true),
        ));
    }
}
