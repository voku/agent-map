<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Context\SourceMaterializer;
use voku\AgentMap\Index\FileEntry;

final class SourceMaterializerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-source-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testOneLineDocBlockDoesNotAbsorbUnrelatedCode(): void
    {
        $path = $this->root . '/src/Example.php';
        file_put_contents($path, <<<'PHP'
<?php

final class Example
{
    public function unrelated(): void
    {
    }

    /** @return non-empty-string */
    public function target(): string
    {
        return 'ok';
    }
}
PHP);
        $hash = hash_file('sha256', $path);
        self::assertIsString($hash);
        $file = new FileEntry('src/Example.php', 'sha256:' . $hash, '', []);

        $source = (new SourceMaterializer())->materialize($this->root, $file, 10, 13);

        self::assertSame(8, $source['start']);
        self::assertStringContainsString('/** @return non-empty-string */', $source['content']);
        self::assertStringNotContainsString('unrelated', $source['content']);
    }
}
