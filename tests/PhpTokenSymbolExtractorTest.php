<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Extract\PhpTokenSymbolExtractor;

final class PhpTokenSymbolExtractorTest extends TestCase
{
    public function testExtractsSymbolsAndMethods(): void
    {
        $code = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Map;

        use Attribute;

        #[Attribute]
        final readonly class ExampleService implements ExampleInterface
        {
            public function run(): void
            {
            }

            private function helper(): string
            {
                return 'ok';
            }
        }

        interface ExampleInterface
        {
            public function run(): void;
        }

        trait ExampleTrait
        {
            protected function fromTrait(): void
            {
            }
        }

        enum ExampleEnum: string
        {
            case A = 'a';
        }

        function helper_function(): void
        {
        }
        PHP;

        $symbols = (new PhpTokenSymbolExtractor())->extract('fixture.php', $code);
        $fqns = array_map(static fn ($symbol): string => $symbol->fqn, $symbols);

        self::assertContains('Demo\Map\ExampleService', $fqns);
        self::assertContains('Demo\Map\ExampleInterface', $fqns);
        self::assertContains('Demo\Map\ExampleTrait', $fqns);
        self::assertContains('Demo\Map\ExampleEnum', $fqns);
        self::assertContains('Demo\Map\helper_function', $fqns);

        $service = $symbols[0];
        self::assertSame('ExampleService', $service->name);
        self::assertSame('run', $service->methods[0]->name);
        self::assertSame('public', $service->methods[0]->visibility);
        self::assertSame('helper', $service->methods[1]->name);
        self::assertSame('private', $service->methods[1]->visibility);

        $trait = $symbols[2];
        self::assertSame('fromTrait', $trait->methods[0]->name);
        self::assertSame('protected', $trait->methods[0]->visibility);
    }

    public function testExtractsSymbolsFromMultipleNamespaces(): void
    {
        $code = <<<'PHP'
        <?php

        namespace Demo\One;

        final class FirstService
        {
        }

        function first_helper(): void
        {
        }

        namespace Demo\Two {
            final class SecondService
            {
            }

            function second_helper(): void
            {
            }
        }
        PHP;

        $symbols = (new PhpTokenSymbolExtractor())->extract('fixture.php', $code);
        $fqns = array_map(static fn ($symbol): string => $symbol->fqn, $symbols);

        self::assertContains('Demo\One\FirstService', $fqns);
        self::assertContains('Demo\One\first_helper', $fqns);
        self::assertContains('Demo\Two\SecondService', $fqns);
        self::assertContains('Demo\Two\second_helper', $fqns);
    }

    public function testInterpolatedStringBracesDoNotEndClassRange(): void
    {
        $code = <<<'PHP'
        <?php

        namespace Demo\Map;

        final class StringyService
        {
            public function render(string $name): string
            {
                return "Hello {$name}";
            }

            private function helper(): void
            {
            }
        }
        PHP;

        $symbols = (new PhpTokenSymbolExtractor())->extract('fixture.php', $code);

        self::assertCount(1, $symbols);
        self::assertSame('Demo\Map\StringyService', $symbols[0]->fqn);
        self::assertSame(['render', 'helper'], array_map(static fn ($method): string => $method->name, $symbols[0]->methods));
    }
}
