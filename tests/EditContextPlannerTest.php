<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Context\ContextRole;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapBuilder;

final class EditContextPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-context-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Contest', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents($this->root . '/src/Services.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

interface UserServiceInterface
{
    public function save(User $user): void;
}

final class UserService implements UserServiceInterface
{
    public function __construct(private UserRepository $repository) {}

    public function save(User $user): void
    {
        $this->repository->persist($user);
    }
}

final class UserController
{
    public function submit(UserService $service, User $user): void
    {
        $service->save($user);
    }
}

final class UserRepository
{
    public function persist(User $user): void {}
}

final class User {}
PHP);
        file_put_contents($this->root . '/src/Contest/ContestCaller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo\Contest;

use Demo\User;
use Demo\UserService;

final class ContestCaller
{
    public function submit(UserService $service, User $user): void
    {
        $service->save($user);
    }
}
PHP);
        file_put_contents($this->root . '/tests/UserControllerTest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo\Tests;

use Demo\User;
use Demo\UserController;
use Demo\UserService;

final class UserControllerTest
{
    public function testSubmit(UserController $controller, UserService $service, User $user): void
    {
        $controller->submit($service, $user);
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPlanContainsTargetContractCallerCalleeAndCallerTest(): void
    {
        $index = (new AgentMapBuilder())->build($this->root, ['src', 'tests'], []);
        $plan = (new EditContextPlanner())->plan($index, 'Demo\UserService::save');

        $roles = [];
        foreach ($plan->slices as $slice) {
            foreach ($slice->roles as $role) {
                $roles[$role] = true;
            }
        }

        self::assertArrayHasKey(ContextRole::PRIMARY, $roles);
        self::assertArrayHasKey(ContextRole::CONTRACT, $roles);
        self::assertArrayHasKey(ContextRole::CHANGE_CANDIDATE, $roles);
        self::assertArrayHasKey(ContextRole::DEPENDENCY, $roles);
        self::assertArrayHasKey(ContextRole::VERIFICATION, $roles);

        $contestSlices = array_values(array_filter(
            $plan->slices,
            static fn ($slice): bool => $slice->path === 'src/Contest/ContestCaller.php',
        ));
        self::assertCount(1, $contestSlices);
        self::assertContains(ContextRole::CHANGE_CANDIDATE, $contestSlices[0]->roles);
        self::assertNotContains(ContextRole::VERIFICATION, $contestSlices[0]->roles);
        self::assertSame('Demo\UserService::save', $plan->resolvedTarget->owner->fqn . '::' . $plan->resolvedTarget->method->name);
        self::assertNotSame('', $plan->mapDigest);
    }

    public function testRejectsZeroMaximumFiles(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EditContextPolicy(maximumFiles: 0);
    }

    public function testManyOverridesCanBeOmittedInsteadOfMakingThePlanFail(): void
    {
        file_put_contents($this->root . '/src/ManyService.php', "<?php\nnamespace Demo;\ninterface ManyService { public function run(): void; }\n");
        foreach (['A', 'B', 'C'] as $suffix) {
            file_put_contents(
                $this->root . '/src/Many' . $suffix . '.php',
                "<?php\nnamespace Demo;\nfinal class Many{$suffix} implements ManyService { public function run(): void {} }\n",
            );
        }

        $index = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $plan = (new EditContextPlanner())->plan(
            $index,
            'Demo\ManyService::run',
            new EditContextPolicy(maximumFiles: 1),
        );

        self::assertNotEmpty($plan->omitted);
        self::assertSame('Demo\ManyService::run', $plan->resolvedTarget->owner->fqn . '::' . $plan->resolvedTarget->method->name);
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
