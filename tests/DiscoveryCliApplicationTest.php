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

final class DiscoveryCliApplicationTest extends TestCase
{
    private string $root;
    private string $indexPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-discovery-cli-' . bin2hex(random_bytes(6));
        $this->indexPath = $this->root . '/map.json';
        $files = [];

        foreach ([
            'legacy/auth/Login.php',
            'legacy/auth/Logout.php',
            'legacy/auth/Session.php',
            'legacy/billing/Invoice.php',
            'legacy/billing/Order.php',
            'legacy/billing/Price.php',
        ] as $path) {
            $absolute = $this->root . '/' . $path;
            $directory = dirname($absolute);
            if (!is_dir($directory)) {
                mkdir($directory, 0o775, true);
            }
            file_put_contents($absolute, "<?php\n");
            $hash = hash_file('sha256', $absolute);
            self::assertIsString($hash);
            $files[] = new FileEntry($path, 'sha256:' . $hash, '', []);
        }

        (new IndexWriter())->write(
            new AgentMapIndex('2.0', $this->root, 'test', $files),
            $this->indexPath,
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

    public function testRegionJsonContainsStructuredArchitecturePath(): void
    {
        ob_start();
        $exit = (new DiscoveryCliApplication())->run([
            'agent-map',
            'discover',
            '--index=' . $this->indexPath,
            '--region=Auth',
            '--format=json',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertJson($output);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('discover_region', $decoded['type']);
        self::assertSame('Auth', $decoded['region']['label']);
        self::assertNotEmpty($decoded['path']);
        self::assertSame($decoded['region']['id'], $decoded['path'][0]['id']);
        self::assertSame('Auth', $decoded['path'][0]['label']);
        self::assertSame('system', $decoded['path'][0]['kind']);
    }
}
