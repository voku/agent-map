<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;

final class SimplePhpParserSymbolExtractorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-simple-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        foreach (glob($this->root . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root);
    }

    public function testExtractsClassSignatureWithExtendsImplementsAndTypedMethods(): void
    {
        $file = $this->write('ExampleService', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Map;

        final class ExampleService extends BaseService implements ExampleInterface
        {
            public function run(string $name, int $limit = 10): bool
            {
                return true;
            }

            private static function helper(): string
            {
                return 'ok';
            }
        }
        PHP);

        $result = (new SimplePhpParserSymbolExtractor())->extract($file);

        self::assertTrue($result->ok);
        self::assertNull($result->error);

        $service = $result->symbols[0];
        self::assertSame('class', $service->kind);
        self::assertSame('ExampleService', $service->name);
        self::assertSame('Demo\Map\ExampleService', $service->fqn);
        self::assertSame(['Demo\Map\BaseService'], $service->extends);
        self::assertSame(['Demo\Map\ExampleInterface'], $service->implements);
        self::assertSame(7, $service->lineStart);
        self::assertSame(18, $service->lineEnd);

        self::assertSame('run', $service->methods[0]->name);
        self::assertSame('public', $service->methods[0]->visibility);
        self::assertFalse($service->methods[0]->static);
        self::assertSame(['string $name', 'int $limit'], $service->methods[0]->params);
        self::assertSame('bool', $service->methods[0]->returnType);
        self::assertSame(9, $service->methods[0]->lineStart);
        self::assertSame(12, $service->methods[0]->lineEnd);

        self::assertSame('helper', $service->methods[1]->name);
        self::assertSame('private', $service->methods[1]->visibility);
        self::assertTrue($service->methods[1]->static);
        self::assertSame('string', $service->methods[1]->returnType);
        self::assertSame(14, $service->methods[1]->lineStart);
        self::assertSame(17, $service->methods[1]->lineEnd);
    }

    public function testExtractsInterfaceTraitEnumAndFunctionSignature(): void
    {
        $file = $this->write('Mixed', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Map;

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

        function helper_function(string $value): int
        {
            return 1;
        }
        PHP);

        $result = (new SimplePhpParserSymbolExtractor())->extract($file);
        self::assertTrue($result->ok);

        $kinds = [];
        foreach ($result->symbols as $symbol) {
            $kinds[$symbol->fqn] = $symbol;
        }

        self::assertSame('interface', $kinds['Demo\Map\ExampleInterface']->kind);
        // Interface method has no body ('...): void;') — line_end falls back to line_start.
        self::assertSame(9, $kinds['Demo\Map\ExampleInterface']->methods[0]->lineStart);
        self::assertSame(9, $kinds['Demo\Map\ExampleInterface']->methods[0]->lineEnd);

        self::assertSame('trait', $kinds['Demo\Map\ExampleTrait']->kind);
        self::assertSame('protected', $kinds['Demo\Map\ExampleTrait']->methods[0]->visibility);
        self::assertSame(14, $kinds['Demo\Map\ExampleTrait']->methods[0]->lineStart);
        self::assertSame(16, $kinds['Demo\Map\ExampleTrait']->methods[0]->lineEnd);

        self::assertSame('enum', $kinds['Demo\Map\ExampleEnum']->kind);

        $function = $kinds['Demo\Map\helper_function'];
        self::assertSame('function', $function->kind);
        self::assertSame(24, $function->lineStart);
        self::assertSame(27, $function->lineEnd);
        self::assertSame(['string $value'], $function->params);
        self::assertSame('int', $function->returnType);
    }

    public function testSkipsAnonymousClassAndKeepsLineEndAlignedForClassesAfterIt(): void
    {
        $file = $this->write('WithAnonymous', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Map;

        $migration = new class() implements Migration {
            public function up(): void
            {
            }
        };

        final class RealService
        {
            public function run(): void
            {
            }
        }
        PHP);

        $result = (new SimplePhpParserSymbolExtractor())->extract($file);
        self::assertTrue($result->ok);

        self::assertCount(1, $result->symbols);
        $service = $result->symbols[0];
        self::assertSame('Demo\Map\RealService', $service->fqn);
        self::assertGreaterThanOrEqual($service->lineStart, $service->lineEnd);
    }

    public function testExtractsTraitUsesFromClassAndTrait(): void
    {
        $file = $this->write('WithTraits', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Map;

        trait ComposedTrait
        {
            use LoggableTrait;
        }

        final class Widget
        {
            use LoggableTrait, \Demo\Shared\CacheableTrait;

            public function run(): void
            {
            }
        }
        PHP);

        $result = (new SimplePhpParserSymbolExtractor())->extract($file);
        self::assertTrue($result->ok);

        $byFqn = [];
        foreach ($result->symbols as $symbol) {
            $byFqn[$symbol->fqn] = $symbol;
        }

        self::assertSame(['Demo\Map\LoggableTrait'], $byFqn['Demo\Map\ComposedTrait']->uses);
        self::assertSame(['Demo\Map\LoggableTrait', 'Demo\Shared\CacheableTrait'], $byFqn['Demo\Map\Widget']->uses);
        self::assertSame('run', $byFqn['Demo\Map\Widget']->methods[0]->name);
    }

    public function testExtractsClassAndMethodAttributes(): void
    {
        $file = $this->write('Attributed', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Map;

        #[Entity]
        #[Table(name: 'widgets', schema: 'public')]
        final class Widget
        {
            #[Route('/widgets', priority: 5)]
            public function index(): void
            {
            }

            public function plain(): void
            {
            }
        }

        #[Deprecated]
        function legacy_helper(): void
        {
        }
        PHP);

        $result = (new SimplePhpParserSymbolExtractor())->extract($file);
        self::assertTrue($result->ok);

        $widget = $result->symbols[0];
        self::assertSame(['Demo\Map\Entity', "Demo\Map\Table(name: 'widgets', schema: 'public')"], $widget->attributes);

        self::assertSame('index', $widget->methods[0]->name);
        self::assertSame(["Demo\Map\Route('/widgets', priority: 5)"], $widget->methods[0]->attributes);

        self::assertSame('plain', $widget->methods[1]->name);
        self::assertSame([], $widget->methods[1]->attributes);

        $function = $result->symbols[1];
        self::assertSame('legacy_helper', $function->name);
        self::assertSame(['Demo\Map\Deprecated'], $function->attributes);
    }

    public function testReportsFailureForInvalidSyntax(): void
    {
        $file = $this->root . '/Broken.php';
        file_put_contents($file, '<?php class Broken {');

        $result = (new SimplePhpParserSymbolExtractor())->extract($file);

        self::assertFalse($result->ok);
        self::assertNotNull($result->error);
    }

    private function write(string $className, string $code): string
    {
        $file = $this->root . '/' . $className . '.php';
        file_put_contents($file, $code);

        return $file;
    }
}
