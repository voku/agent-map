<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Cli\DiscoveryCliApplication;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;

final class DiscoveryDefaultIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-default-index-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-map', 0o775, true);

        $source = $this->root . '/src/Foo.php';
        file_put_contents($source, "<?php\n");
        $hash = hash_file('sha256', $source);
        self::assertIsString($hash);

        (new IndexWriter())->write(
            new AgentMapIndex(
                '2.0',
                $this->root,
                'test',
                [new FileEntry('src/Foo.php', 'sha256:' . $hash, '', [])],
            ),
            $this->root . '/.agent-map/php-symbols.json',
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testDiscoveryWithoutIndexUsesStandaloneDefault(): void
    {
        $previousDirectory = getcwd();
        self::assertNotFalse($previousDirectory);
        self::assertTrue(chdir($this->root));

        try {
            ob_start();
            $exit = (new DiscoveryCliApplication())->run([
                'agent-map',
                'discover',
                '--format=json',
            ]);
            $output = (string) ob_get_clean();
        } finally {
            chdir($previousDirectory);
        }

        self::assertSame(0, $exit);
        self::assertJson($output);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('discover', $decoded['type']);
    }
}
