<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Scaffold\UseStatementLocator;

final class UseStatementLocatorTest extends TestCase
{
    private UseStatementLocator $locator;

    protected function setUp(): void
    {
        $this->locator = new UseStatementLocator();
    }

    public function testRecognizesBuiltinTypes(): void
    {
        self::assertTrue($this->locator->isBuiltinType('string'));
        self::assertTrue($this->locator->isBuiltinType('int'));
        self::assertTrue($this->locator->isBuiltinType('bool'));
        self::assertTrue($this->locator->isBuiltinType('float'));
        self::assertTrue($this->locator->isBuiltinType('array'));
        self::assertTrue($this->locator->isBuiltinType('void'));
        self::assertTrue($this->locator->isBuiltinType('callable'));
        self::assertTrue($this->locator->isBuiltinType('iterable'));
        self::assertTrue($this->locator->isBuiltinType('self'));
        self::assertTrue($this->locator->isBuiltinType('static'));
        self::assertTrue($this->locator->isBuiltinType('parent'));
        self::assertFalse($this->locator->isBuiltinType('DateTimeImmutable'));
        self::assertFalse($this->locator->isBuiltinType('App\Entity\User'));
    }

    public function testInsertsAfterExistingUseStatement(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;

final class UserService
{
}
PHP;

        $insertion = $this->locator->findUseInsertion($code, 'DateTimeImmutable');
        self::assertNotNull($insertion);
        self::assertSame("use DateTimeImmutable;\n", $insertion['insertion']);
        self::assertSame('', $insertion['expected']);

        $simulated = substr($code, 0, $insertion['start']) . $insertion['insertion'] . substr($code, $insertion['start']);
        self::assertStringContainsString("use App\Repository\UserRepository;\nuse DateTimeImmutable;\n", $simulated);
    }

    public function testInsertsAfterNamespaceWhenNoUseStatements(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

final class PlainService
{
}
PHP;

        $insertion = $this->locator->findUseInsertion($code, 'App\Repository\UserRepository');
        self::assertNotNull($insertion);
        self::assertSame("\nuse App\Repository\UserRepository;\n", $insertion['insertion']);
        self::assertSame('', $insertion['expected']);

        $simulated = substr($code, 0, $insertion['start']) . $insertion['insertion'] . substr($code, $insertion['start']);
        self::assertStringContainsString("namespace App\Service;\n\nuse App\Repository\UserRepository;\n", $simulated);
    }

    public function testReturnsNullWhenAlreadyImported(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;

final class UserService
{
}
PHP;

        self::assertNull($this->locator->findUseInsertion($code, 'App\Repository\UserRepository'));
    }

    public function testReturnsNullWhenInSameNamespace(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

final class UserService
{
}
PHP;

        self::assertNull($this->locator->findUseInsertion($code, 'App\Service\OtherService'));
    }

    public function testReturnsNullWhenAliasCollides(): void
    {
        $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

use Other\Vendor\User;

final class UserService
{
}
PHP;

        // Trying to import App\Entity\User would collide with existing User import
        self::assertNull($this->locator->findUseInsertion($code, 'App\Entity\User'));
    }
}
