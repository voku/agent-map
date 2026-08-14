<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\MapArtifactPaths;

final class MapArtifactCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-artifact-cache-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Foo.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo { public function run(): void {} }\n",
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testBuildKeepsEveryCacheBelowInjectedMapRoot(): void
    {
        $artifacts = MapArtifactPaths::forProject($this->root, 'var/agent-state/map');

        (new AgentMapBuilder(artifacts: $artifacts))->build($this->root, ['src'], []);

        self::assertFileExists($artifacts->structuralCache());
        self::assertDirectoryExists($artifacts->phpStanCache());
        self::assertDirectoryDoesNotExist($this->root . '/.agent-map');
    }
}
