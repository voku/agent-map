<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\IO\PhpFileFinder;

final class PhpFileFinderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-finder-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        mkdir($this->root . '/vendor/package', 0o775, true);
        mkdir($this->root . '/var/cache', 0o775, true);
        file_put_contents($this->root . '/src/Keep.php', '<?php');
        file_put_contents($this->root . '/src/GeneratedProxy.php', '<?php');
        file_put_contents($this->root . '/tests/KeepTest.php', '<?php');
        file_put_contents($this->root . '/vendor/package/Skip.php', '<?php');
        file_put_contents($this->root . '/var/cache/SkipCache.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFindsPhpFilesAndExcludesDefaults(): void
    {
        $files = (new PhpFileFinder())->find($this->root, ['src', 'tests', 'vendor', 'var']);

        self::assertSame(['src/GeneratedProxy.php', 'src/Keep.php', 'tests/KeepTest.php'], $files);
    }

    public function testSupportsCustomRegexExcludes(): void
    {
        $files = (new PhpFileFinder())->find($this->root, ['src', 'tests', 'var'], ['~Generated.*\.php$~', '~(^|/)var/cache(/|$)~']);

        self::assertSame(['src/Keep.php', 'tests/KeepTest.php'], $files);
    }

    public function testRejectsInvalidRegex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid exclude regex');

        (new PhpFileFinder())->find($this->root, ['src'], ['~[~']);
    }

    public function testReturnsSortedPaths(): void
    {
        $files = (new PhpFileFinder())->find($this->root, ['tests', 'src']);

        self::assertSame(['src/GeneratedProxy.php', 'src/Keep.php', 'tests/KeepTest.php'], $files);
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
