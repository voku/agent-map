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

    private string $outsideFile;

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
        $this->outsideFile = dirname($this->root) . '/agent-map-finder-outside-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($this->outsideFile, '<?php');
    }

    protected function tearDown(): void
    {
        if (is_file($this->outsideFile)) {
            unlink($this->outsideFile);
        }

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

    public function testFindsAnExplicitPhpFile(): void
    {
        $files = (new PhpFileFinder())->find($this->root, ['src/Keep.php']);

        self::assertSame(['src/Keep.php'], $files);
    }

    public function testIgnoresExplicitFileOutsideRoot(): void
    {
        $files = (new PhpFileFinder())->find($this->root, ['../' . basename($this->outsideFile)]);

        self::assertSame([], $files);
    }

    public function testNestedDefaultExclusionsArePrunedWithoutSkippingSimilarNames(): void
    {
        foreach (['vendor', '.git', 'node_modules', 'var/cache'] as $ignored) {
            mkdir($this->root . '/src/packages/' . $ignored, 0o775, true);
            file_put_contents($this->root . '/src/packages/' . $ignored . '/Skip.php', '<?php');
        }
        mkdir($this->root . '/src/packages/vendor-like', 0o775, true);
        mkdir($this->root . '/src/packages/var/cache-like', 0o775, true);
        file_put_contents($this->root . '/src/packages/vendor-like/Keep.php', '<?php');
        file_put_contents($this->root . '/src/packages/var/cache-like/Keep.php', '<?php');

        $files = (new PhpFileFinder())->find($this->root, ['.']);

        self::assertSame([
            'src/GeneratedProxy.php',
            'src/Keep.php',
            'src/packages/var/cache-like/Keep.php',
            'src/packages/vendor-like/Keep.php',
            'tests/KeepTest.php',
        ], $files);
    }

    public function testCustomRegexMatchingOnlyADirectoryDoesNotPruneItsChildren(): void
    {
        mkdir($this->root . '/src/Area', 0o775, true);
        file_put_contents($this->root . '/src/Area/Keep.php', '<?php');
        file_put_contents($this->root . '/src/Area/Skip.php', '<?php');

        $files = (new PhpFileFinder())->find(
            $this->root,
            ['src'],
            ['~^src/Area$~', '~^src/Area/Skip[.]php$~'],
        );

        self::assertSame([
            'src/Area/Keep.php',
            'src/GeneratedProxy.php',
            'src/Keep.php',
        ], $files);
    }

    public function testExplicitDefaultExcludedScopeStillYieldsNothing(): void
    {
        self::assertSame(
            [],
            (new PhpFileFinder())->find($this->root, ['vendor', 'var/cache']),
        );
    }

    public function testOverlappingPathsReturnEachFileOnlyOnce(): void
    {
        self::assertSame(
            ['src/GeneratedProxy.php', 'src/Keep.php'],
            (new PhpFileFinder())->find($this->root, ['src', 'src/Keep.php', 'src']),
        );
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
