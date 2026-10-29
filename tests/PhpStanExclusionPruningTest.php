<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\IO\PhpFileFinder;

final class PhpStanExclusionPruningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-exclusions-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Module', 0775, true);
        mkdir($this->root . '/src/Module/vendor/dependency', 0775, true);
        mkdir($this->root . '/src/node_modules/lib', 0775, true);
        mkdir($this->root . '/src/var/cache/temporary', 0775, true);
        mkdir($this->root . '/src/ignored/custom', 0775, true);
        mkdir($this->root . '/src/.git/hooks', 0775, true);
        file_put_contents($this->root . '/src/Module/Included.php', "<?php\nnamespace Fixture;\nclass Included {}\n");
        file_put_contents($this->root . '/src/Module/vendor/dependency/Skip.php', "<?php\nnamespace Fixture;\nclass SkipVendor {}\n");
        file_put_contents($this->root . '/src/node_modules/lib/Skip.php', "<?php\nnamespace Fixture;\nclass SkipNode {}\n");
        file_put_contents($this->root . '/src/var/cache/temporary/Skip.php', "<?php\nnamespace Fixture;\nclass SkipCache {}\n");
        file_put_contents($this->root . '/src/.git/hooks/Skip.php', "<?php\nnamespace Fixture;\nclass SkipGit {}\n");
        file_put_contents($this->root . '/src/ignored/custom/Skip.php', "<?php\nnamespace Fixture;\nclass SkipCustom {}\n");
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testDefaultSubtreesAndCallerRegexExclusionArePreserved(): void
    {
        $included = (new PhpFileFinder())->find($this->root, ['src'], ['~(^|/)ignored(/|$)~']);
        self::assertSame(['src/Module/Included.php'], $included);

        $excludes = $this->exclusions(['src'], $included);
        self::assertSame([
            $this->root . '/src/.git',
            $this->root . '/src/Module/vendor',
            $this->root . '/src/ignored',
            $this->root . '/src/node_modules',
            $this->root . '/src/var/cache',
        ], $excludes);
    }

    public function testRootScopeStillExcludesOnlyUnindexedDirectories(): void
    {
        $included = (new PhpFileFinder())->find($this->root, ['.']);
        $excludes = $this->exclusions(['.'], $included);
        self::assertContains($this->root . '/src/Module/vendor', $excludes);
        self::assertContains($this->root . '/src/node_modules', $excludes);
        self::assertContains($this->root . '/src/var/cache', $excludes);
        self::assertNotContains($this->root . '/src/ignored', $excludes, 'Caller regex must not be pruned without an explicit regex.');
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $included
     * @return list<string>
     */
    private function exclusions(array $scopes, array $included): array
    {
        $method = new ReflectionMethod(PhpStanSemanticAnalyzer::class, 'excludedPaths');
        $value = $method->invoke(new PhpStanSemanticAnalyzer(), $this->root, $scopes, $included);
        self::assertIsArray($value);

        return $value;
    }
}
