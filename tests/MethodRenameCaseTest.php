<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** PHP method names are case-insensitive even when source spelling is not. */
final class MethodRenameCaseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-rename-case-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php
namespace RenameCase;
final class Service
{
    public function oldName(): void
    {
    }
}
PHP);
        file_put_contents($this->root . '/src/Caller.php', <<<'PHP'
<?php
namespace RenameCase;
final class Caller
{
    public function run(Service $service): void
    {
        $service->OLDNAME();
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testResolvedCallKeepsActualSourceCasingAsExpectedToken(): void
    {
        $map = new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'simple-php-code-parser+phpstan',
            files: [$this->file('src/Service.php'), $this->file('src/Caller.php')],
            relations: [
                RelationEntry::create(
                    sourceId: 'method:RenameCase\\Caller::run',
                    kind: 'calls',
                    targetIds: ['method:RenameCase\\Service::oldName'],
                    file: 'src/Caller.php',
                    lineStart: 7,
                    lineEnd: 7,
                    resolution: 'phpstan_resolved',
                    receiverType: 'RenameCase\\Service',
                    resultType: 'void',
                ),
            ],
        );

        $plan = (new MethodRenamePlanner())->plan($map, 'RenameCase\\Service::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(2, $plan->edits);
        $callEdits = array_values(array_filter($plan->edits, static fn ($edit): bool => $edit->role === 'call'));
        self::assertCount(1, $callEdits);
        self::assertSame('OLDNAME', $callEdits[0]->expected);
        self::assertSame('renamed', $callEdits[0]->replacement);
    }

    /** Builds one structural file entry through the production parser adapter. */
    private function file(string $path): FileEntry
    {
        $absolute = $this->root . '/' . $path;
        $result = (new SimplePhpParserSymbolExtractor())->extract($absolute);
        self::assertTrue($result->ok, $result->error ?? 'Parser extraction failed.');
        $hash = hash_file('sha256', $absolute);
        self::assertIsString($hash);

        return new FileEntry(
            path: $path,
            sha256: 'sha256:' . $hash,
            namespace: 'RenameCase',
            symbols: $result->symbols,
            semanticStatus: 'analysed',
        );
    }
}
