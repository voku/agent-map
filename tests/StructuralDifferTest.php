<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Temporal\StructuralDiffer;
use voku\AgentMap\Temporal\TemporalEvent;

final class StructuralDifferTest extends TestCase
{
    public function testLineNumberOnlyRelationMovementIsNotReportedAsEvolution(): void
    {
        $before = $this->map(
            [$this->file('src/Foo.php', 'sha256:foo', 'App\\Foo', [new MethodEntry('run', 'public', 10, 20)])],
            [RelationEntry::create('method:App\\Foo::run', 'calls', ['method:App\\Bar::go'], 'src/Foo.php', 12, 12, 'phpstan_resolved')],
        );
        $after = $this->map(
            [$this->file('src/Foo.php', 'sha256:foo', 'App\\Foo', [new MethodEntry('run', 'public', 30, 40)])],
            [RelationEntry::create('method:App\\Foo::run', 'calls', ['method:App\\Bar::go'], 'src/Foo.php', 32, 32, 'phpstan_resolved')],
        );

        self::assertSame([], (new StructuralDiffer())->diff($before, $after)->events);
    }

    public function testReportsMethodLifecycleSignatureAndSymbolMovementAsFacts(): void
    {
        $before = $this->map([
            $this->file('src/Foo.php', 'sha256:before', 'App\\Foo', [
                new MethodEntry('run', 'public', 10, 20, nativeReturnType: 'int'),
                new MethodEntry('removed', 'private', 22, 25),
            ]),
        ]);
        $after = $this->map([
            $this->file('src/Domain/Foo.php', 'sha256:after', 'App\\Foo', [
                new MethodEntry('run', 'public', 10, 20, nativeReturnType: 'string'),
                new MethodEntry('added', 'private', 22, 25),
            ]),
        ]);

        $events = (new StructuralDiffer())->diff($before, $after)->events;
        $kinds = array_map(static fn (TemporalEvent $event): string => $event->kind, $events);

        self::assertContains('file_removed', $kinds);
        self::assertContains('file_added', $kinds);
        self::assertContains('symbol_moved', $kinds);
        self::assertContains('method_removed', $kinds);
        self::assertContains('method_added', $kinds);
        self::assertContains('method_signature_changed', $kinds);
        self::assertNotContains('symbol_signature_changed', $kinds, 'Method changes must not masquerade as class declaration changes.');
    }

    public function testRelationMultiplicityChangesWithoutDependingOnCallSiteLines(): void
    {
        $file = $this->file('src/Foo.php', 'sha256:foo', 'App\\Foo', [new MethodEntry('run', 'public', 10, 20)]);
        $relation = static fn (int $line): RelationEntry => RelationEntry::create(
            'method:App\\Foo::run',
            'calls',
            ['method:App\\Bar::go'],
            'src/Foo.php',
            $line,
            $line,
            'phpstan_resolved',
        );

        $report = (new StructuralDiffer())->diff(
            $this->map([$file], [$relation(12)]),
            $this->map([$file], [$relation(40), $relation(60)]),
        );

        self::assertCount(1, $report->events);
        self::assertSame('relation_multiplicity_changed', $report->events[0]->kind);
        self::assertSame(1, $report->events[0]->before['count']);
        self::assertSame(2, $report->events[0]->after['count']);
    }

    public function testDiffIsDeterministic(): void
    {
        $before = $this->map([$this->file('src/Foo.php', 'sha256:before', 'App\\Foo', [])]);
        $after = $this->map([$this->file('src/Foo.php', 'sha256:after', 'App\\Foo', [new MethodEntry('run', 'public', 5, 10)])]);
        $differ = new StructuralDiffer();

        self::assertSame($differ->diff($before, $after)->toArray(), $differ->diff($before, $after)->toArray());
    }

    /**
     * @param list<FileEntry> $files
     * @param list<RelationEntry> $relations
     */
    private function map(array $files, array $relations = []): AgentMapIndex
    {
        return new AgentMapIndex('2.0', '/tmp/agent-map-temporal-test', 'test', $files, $relations);
    }

    /** @param list<MethodEntry> $methods */
    private function file(string $path, string $sha, string $fqn, array $methods): FileEntry
    {
        $parts = explode('\\', $fqn);
        $name = (string) end($parts);
        $namespace = implode('\\', array_slice($parts, 0, -1));

        return new FileEntry(
            $path,
            $sha,
            $namespace,
            [new SymbolEntry('class', $name, $fqn, 1, 100, $methods)],
        );
    }
}
