<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

final class PhpStanRefreshDependencyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('PHPStan refresh dependency regression requires phpstan/phpstan.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-phpstan-refresh-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        file_put_contents($this->root . '/src/A.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

class A
{
    public function run(): void
    {
    }
}
PHP);
        file_put_contents($this->root . '/src/B.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

class B
{
    public function run(): void
    {
    }
}
PHP);
        $this->writeChild('A');
        file_put_contents($this->root . '/src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Caller
{
    public function call(Child $child): void
    {
        $child->run();
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testRefreshReanalysesUnchangedSemanticDependents(): void
    {
        $map = $this->root . '/map.json';
        $build = $this->runApp([
            'agent-map',
            'build',
            '--root=' . $this->root,
            '--paths=src',
            '--backend=phpstan',
            '--out=' . $map,
        ]);
        self::assertSame(0, $build['exit'], $build['output']);
        self::assertSame(['method:Demo\\A::run'], $this->callerTargets((new IndexReader())->read($map)));

        $this->writeChild('B');

        $refresh = $this->runApp([
            'agent-map',
            'refresh',
            '--root=' . $this->root,
            '--backend=phpstan',
            '--index=' . $map,
            '--out=' . $map,
        ]);
        self::assertSame(0, $refresh['exit'], $refresh['output']);
        self::assertSame(['method:Demo\\B::run'], $this->callerTargets((new IndexReader())->read($map)));
    }

    /**
     * @return list<string>
     */
    private function callerTargets(AgentMapIndex $index): array
    {
        foreach ($index->relations as $relation) {
            if ($relation->kind !== 'calls' || $relation->sourceId !== 'method:Demo\\Caller::call') {
                continue;
            }

            return $relation->targetIds;
        }

        throw new RuntimeException('Caller relation missing from semantic map.');
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runApp(array $argv): array
    {
        ob_start();
        $exit = (new AgentMapApplication())->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function writeChild(string $parent): void
    {
        file_put_contents($this->root . '/src/Child.php', <<<PHP
<?php

declare(strict_types=1);

namespace Demo;

final class Child extends {$parent}
{
}
PHP);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
