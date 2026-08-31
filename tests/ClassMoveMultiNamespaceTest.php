<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Move\ClassMovePlan;
use voku\AgentMap\Move\ClassMovePlanner;

final class ClassMoveMultiNamespaceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-move-multi-ns-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Legacy', 0o775, true);
        mkdir($this->root . '/src/Client', 0o775, true);
        file_put_contents($this->root . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->root . '/src/Legacy/UserService.php',
            "<?php\nnamespace App\\Legacy;\nfinal class UserService {}\n",
        );
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

    public function testMultiNamespaceConsumerBlocksInsteadOfSharingImportsAcrossBlocks(): void
    {
        file_put_contents($this->root . '/src/Client/MultiNamespace.php', <<<'PHP'
<?php
namespace App\Client\One {
    use App\Legacy\UserService;

    final class FirstConsumer
    {
        public function make(): UserService
        {
            return new UserService();
        }
    }
}

namespace App\Client\Two {
    final class SecondConsumer
    {
        public function make(): \App\Legacy\UserService
        {
            return new \App\Legacy\UserService();
        }
    }
}
PHP);

        $map = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );

        $plan = (new ClassMovePlanner())->plan($map, 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('src/Client/MultiNamespace.php', implode("\n", $plan->blockers));
        self::assertStringContainsString('namespace statements', implode("\n", $plan->blockers));
    }
}
