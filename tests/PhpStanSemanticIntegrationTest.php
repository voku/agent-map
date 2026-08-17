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

    public function maybeLoad(?UserRepository $repository): ?Entity
    {
        return $repository?->find(1);
    }
}
PHP);
        file_put_contents($this->root . '/src/Functions.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace {
    function fallback_helper(): int
    {
        return 1;
    }
}

namespace Demo {
    function local_helper(string $value): string
    {
        return $value;
    }

    final class FunctionCaller
    {
        public function call(): int
        {
            return fallback_helper();
        }
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testBuildResolvesGenericTypesCallsFunctionsAndNullsafeCalls(): void
    {
        $index = (new AgentMapBuilder())->build($this->root, ['src'], [], null, '512M');
        $method = $index->resolveMethod('Demo\UserRepository::find');

        self::assertSame('simple-php-code-parser+phpstan', $index->backend);
        self::assertSame('Demo\User|null', $method->method->phpDocReturnType);
        self::assertSame('Demo\User|null', $method->method->resolvedReturnType);
        self::assertSame('semantic_enrichment', $method->method->reconciliationStatus);
        self::assertSame(['Demo\Repository<Demo\User>'], $method->owner->implements);

        $callers = $index->incoming($method->id, 'calls');
        $callerIds = array_map(static fn ($relation): string => $relation->sourceId, $callers);
        sort($callerIds, SORT_STRING);
        self::assertSame([
            'method:Demo\UserService::load',
            'method:Demo\UserService::maybeLoad',
        ], $callerIds);

        $contracts = $index->outgoing($method->id, 'overrides');
        self::assertCount(1, $contracts);
        self::assertSame(['method:Demo\Repository::find'], $contracts[0]->targetIds);

        $functionCalls = array_values(array_filter(
            $index->relations,
            static fn ($relation): bool => $relation->sourceId === 'method:Demo\FunctionCaller::call' && in_array('function:fallback_helper', $relation->targetIds, true),
        ));
        self::assertCount(1, $functionCalls);
        self::assertSame('phpstan_resolved', $functionCalls[0]->resolution);
        self::assertNotNull($index->symbolById('function:fallback_helper'));
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
