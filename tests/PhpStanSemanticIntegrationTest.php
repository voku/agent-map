<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;

final class PhpStanSemanticIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-phpstan-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Repository.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

/** @template T of Entity */
interface Repository
{
    /** @return T|null */
    public function find(int $id): ?Entity;
}

/** @implements Repository<User> */
final class UserRepository implements Repository
{
    public function find(int $id): ?Entity
    {
        return null;
    }
}

class Entity {}
final class User extends Entity {}

final class UserService
{
    public function load(UserRepository $repository): ?Entity
    {
        return $repository->find(1);
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testBuildResolvesGenericTypesAndCalls(): void
    {
        $index = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $method = $index->resolveMethod('Demo\\UserRepository::find');

        self::assertSame('Demo\\User|null', $method->method->phpDocReturnType);
        self::assertSame('Demo\\User|null', $method->method->resolvedReturnType);
        self::assertSame('semantic_enrichment', $method->method->reconciliationStatus);
        self::assertSame(['Demo\\Repository<Demo\\User>'], $method->owner->implements);

        $callers = $index->incoming($method->id, 'calls');
        self::assertCount(1, $callers);
        self::assertSame('method:Demo\\UserService::load', $callers[0]->sourceId);
        self::assertSame('Demo\\User|null', $callers[0]->resultType);

        $contracts = $index->outgoing($method->id, 'overrides');
        self::assertCount(1, $contracts);
        self::assertSame(['method:Demo\\Repository::find'], $contracts[0]->targetIds);
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
