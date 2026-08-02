<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Store\ToonMapReader;
use voku\AgentMap\Store\ToonMapWriter;

final class ToonMapStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-toon-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public function bar(): void {} }\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWritesAndReadsDeterministicToonArtifacts(): void
    {
        $directory = $this->root . '/.agent-map';
        $writer = new ToonMapWriter();
        $index = $this->index();

        $writer->write($index, $directory);
        $first = $this->artifactContents($directory);
        $writer->write($index, $directory);
        $second = $this->artifactContents($directory);

        self::assertSame($first, $second);

        $snapshot = (new ToonMapReader())->read($directory);
        self::assertSame('2.0', $snapshot->manifest['schema_version']);
        self::assertSame('src/Foo.php', $snapshot->files['files'][0]['path']);
        self::assertSame('class:Demo\\Foo', $snapshot->symbols['symbols'][0]['id']);
        self::assertNotEmpty($snapshot->relations['relations']);
    }

    public function testReaderRejectsTamperedArtifact(): void
    {
        $directory = $this->root . '/.agent-map';
        (new ToonMapWriter())->write($this->index(), $directory);
        file_put_contents($directory . '/files.toon', "tampered: true\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hash mismatch');

        (new ToonMapReader())->read($directory);
    }

    private function index(): AgentMapIndex
    {
        $path = $this->root . '/src/Foo.php';

        return new AgentMapIndex(
            '1.0',
            'ignored-by-canonical-store',
            $this->root,
            'simple',
            [
                new FileEntry(
                    'src/Foo.php',
                    (int) filemtime($path),
                    (string) sha1_file($path),
                    'Demo',
                    [
                        new SymbolEntry(
                            'class',
                            'Foo',
                            'Demo\\Foo',
                            2,
                            2,
                            [new MethodEntry('bar', 'public', 2, false, [], 'void', [], 2)],
                        ),
                    ],
                ),
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function artifactContents(string $directory): array
    {
        $contents = [];
        foreach (['manifest', 'files', 'symbols', 'relations', 'diagnostics'] as $name) {
            $content = file_get_contents($directory . '/' . $name . '.toon');
            self::assertIsString($content);
            $contents[$name] = $content;
        }

        return $contents;
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
