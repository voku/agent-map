<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Scaffold\MethodCopyPlan;
use voku\AgentMap\Scaffold\MethodCopyPlanner;

final class MethodCopyPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-method-copy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPlansSafeMethodCopy(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Source
{
    /**
     * Compute square.
     */
    public static function square(int $x): int
    {
        return $x * $x;
    }
}
PHP);

        $this->write('Dest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Dest
{
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodCopyPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            sourceTarget: 'App\Source::square',
            destinationClass: 'App\Dest',
            newMethodName: 'computeSquare',
            visibility: 'public',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('App\Dest', $plan->destinationFqn);
        self::assertSame('computeSquare', $plan->newMethodName);
        self::assertSame([], $plan->ownerDependencies);
        self::assertCount(1, $plan->edits);

        $edit = $plan->edits[0];
        self::assertSame('method_declaration_insertion', $edit->role);
        self::assertStringContainsString('function computeSquare(int $x): int', $edit->replacement);
        self::assertStringContainsString('* Compute square.', $edit->replacement);
    }

    public function testFlagsOwnerDependencies(): void
    {
        $this->write('WithState.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class WithState
{
    private string $name = 'test';

    public function greet(): string
    {
        return 'Hello ' . $this->name;
    }
}
PHP);

        $this->write('Other.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Other
{
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodCopyPlanner($this->root);

        $plan = $planner->plan($map, 'App\WithState::greet', 'App\Other');
        self::assertSame(PlanStatus::REVIEW_REQUIRED, $plan->status);
        self::assertContains('$this', $plan->ownerDependencies);
        self::assertNotEmpty($plan->blindSpots);
    }

    public function testBlocksWhenDestinationAlreadyHasMethod(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

class Source
{
    public function foo(): void {}
}
PHP);

        $this->write('Dest.php', <<<'PHP'
<?php

class Dest
{
    public function foo(): void {}
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodCopyPlanner($this->root);

        $plan = $planner->plan($map, 'Source::foo', 'Dest');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('already exists on destination class Dest', $plan->blockers[0]);
    }

    public function testBlocksWhenBodyUsesAnAliasTheDestinationDoesNotImport(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Lib\Formatter as Fmt;

final class Source
{
    public function format(string $v): string
    {
        return Fmt::apply($v);
    }
}
PHP);
        $this->write('Dest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Dest\n{\n}\n");

        $plan = $this->plan('App\Source::format', 'App\Dest');

        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('resolves differently (Fmt)', $plan->blockers[0]);
    }

    public function testBlocksWhenBodyUsesAnImportedClassTheDestinationLacks(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Lib\Formatter;

final class Source
{
    public function format(string $v): string
    {
        return Formatter::apply($v);
    }
}
PHP);
        $this->write('Dest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Other;\n\nfinal class Dest\n{\n}\n");

        $plan = $this->plan('App\Source::format', 'App\Other\Dest');

        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('resolves differently (Formatter)', $plan->blockers[0]);
    }

    public function testBlocksWhenDestinationImportsADifferentClassUnderTheSameName(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Lib\Formatter;

final class Source
{
    public function format(string $v): string
    {
        return Formatter::apply($v);
    }
}
PHP);
        $this->write('Dest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Other\Formatter;

final class Dest
{
}
PHP);

        $plan = $this->plan('App\Source::format', 'App\Dest');

        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('resolves differently (Formatter)', $plan->blockers[0]);
    }

    public function testStaysSafeWhenDestinationImportsTheSameClassOrAlias(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Lib\Formatter as Fmt;

final class Source
{
    public function format(string $v): string
    {
        return Fmt::apply($v);
    }
}
PHP);
        $this->write('Dest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Elsewhere;

use Lib\Formatter as Fmt;

final class Dest
{
}
PHP);

        $plan = $this->plan('App\Source::format', 'Elsewhere\Dest');

        self::assertSame(PlanStatus::SAFE, $plan->status);
        self::assertSame([], $plan->blockers);
    }

    public function testStaysSafeWhenOnlyTheSignatureNeedsAnImportThePlanInserts(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Lib\Formatter;

final class Source
{
    public function format(Formatter $formatter): string
    {
        return $formatter->name();
    }
}
PHP);
        $this->write('Dest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Elsewhere;\n\nuse Lib\\Other;\n\nfinal class Dest\n{\n}\n");

        $plan = $this->plan('App\Source::format', 'Elsewhere\Dest');

        self::assertSame(PlanStatus::SAFE, $plan->status);
        self::assertSame([], $plan->blockers);
        self::assertContains('use_statement_insertion', array_map(static fn ($edit): string => $edit->role, $plan->edits));
    }

    public function testBlocksAnAliasedSignatureTypeBecauseThePlannedImportDropsTheAlias(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Lib\Formatter as Fmt;

final class Source
{
    public function format(Fmt $formatter): string
    {
        return $formatter->name();
    }
}
PHP);
        $this->write('Dest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Elsewhere;\n\nfinal class Dest\n{\n}\n");

        $plan = $this->plan('App\Source::format', 'Elsewhere\Dest');

        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('resolves differently (Fmt)', $plan->blockers[0]);
    }

    public function testCopyWithinTheSameClassIgnoresImportResolution(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Lib\Formatter as Fmt;

final class Source
{
    public function format(string $v): string
    {
        return Fmt::apply($v);
    }
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan($map, 'App\Source::format', null, 'formatAgain');

        self::assertSame(PlanStatus::SAFE, $plan->status);
        self::assertSame([], $plan->blockers);
    }

    public function testBlocksWhenBodyUsesAnImportedFunctionOrConstantTheDestinationLacks(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use function Lib\run as execute;
use const Lib\LIMIT;

final class Source
{
    public function go(): int
    {
        return execute(LIMIT) + strlen('x');
    }
}
PHP);
        $this->write('Dest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Elsewhere;\n\nfinal class Dest\n{\n}\n");

        $plan = $this->plan('App\Source::go', 'Elsewhere\Dest');

        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('resolves differently (LIMIT, execute)', $plan->blockers[0]);
    }

    public function testBlocksWhenDestinationImportsADifferentFunctionUnderTheSameAlias(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use function Lib\run;

final class Source
{
    public function go(): int
    {
        return run();
    }
}
PHP);
        $this->write('Dest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Elsewhere;

use function Other\run;

final class Dest
{
}
PHP);

        $plan = $this->plan('App\Source::go', 'Elsewhere\Dest');

        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('resolves differently (run)', $plan->blockers[0]);
    }

    public function testStaysSafeWhenDestinationImportsTheSameFunctionAndConstant(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use function Lib\run;
use const Lib\LIMIT;

final class Source
{
    public function go(): int
    {
        return run(LIMIT) + strlen('x') + PHP_INT_SIZE;
    }
}
PHP);
        $this->write('Dest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Elsewhere;

use Lib\{Unused};
use function Lib\{run};
use const Lib\{LIMIT};

final class Dest
{
}
PHP);

        $plan = $this->plan('App\Source::go', 'Elsewhere\Dest');

        self::assertSame(PlanStatus::SAFE, $plan->status);
        self::assertSame([], $plan->blockers);
    }

    public function testCopyIntoACrlfFileKeepsEveryLineBreakCrlfWhateverTheSourceUses(): void
    {
        $this->write('Source.php', "<?php\n\nnamespace App;\n\nuse DateTimeImmutable;\n\nfinal class Source\n{\n    public static function stamp(): DateTimeImmutable\n    {\n        return new DateTimeImmutable('now');\n    }\n}\n");
        $dest = "<?php\r\n\r\nnamespace Elsewhere;\r\n\r\nuse Foo\\Bar;\r\n\r\nfinal class Dest\r\n{\r\n    public const X = 1;\r\n}\r\n";
        $this->write('Dest.php', $dest);

        $plan = $this->plan('App\\Source::stamp', 'Elsewhere\\Dest');

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        $result = $this->applyEdits($dest, $plan->edits);
        self::assertSame(substr_count($result, "\n"), substr_count($result, "\r\n"), 'no bare LF');
        self::assertStringContainsString("use Foo\\Bar;\r\nuse DateTimeImmutable;\r\n", $result);
        self::assertStringContainsString("    public const X = 1;\r\n\r\n    public static function stamp(): DateTimeImmutable\r\n    {\r\n", $result);
    }

    public function testCopyFromACrlfSourceIntoAnLfFileLeavesNoCarriageReturn(): void
    {
        $this->write('Source.php', "<?php\r\n\r\nnamespace App;\r\n\r\nfinal class Source\r\n{\r\n    public static function one(): int\r\n    {\r\n        return 1;\r\n    }\r\n}\r\n");
        $dest = "<?php\n\nnamespace Elsewhere;\n\nfinal class Dest\n{\n    public const X = 1;\n}\n";
        $this->write('Dest.php', $dest);

        $plan = $this->plan('App\\Source::one', 'Elsewhere\\Dest');

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertStringNotContainsString("\r", $this->applyEdits($dest, $plan->edits));
    }

    /** @param list<\voku\AgentMap\Plan\PlanEdit> $edits */
    private function applyEdits(string $source, array $edits): string
    {
        usort($edits, static fn ($a, $b): int => $b->startFilePos <=> $a->startFilePos);
        foreach ($edits as $edit) {
            self::assertSame($edit->expected, substr($source, $edit->startFilePos, strlen($edit->expected)));
            $source = substr($source, 0, $edit->startFilePos) . $edit->replacement . substr($source, $edit->startFilePos + strlen($edit->expected));
        }

        return $source;
    }

    private function plan(string $source, string $destination): MethodCopyPlan
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);

        return (new MethodCopyPlanner($this->root))->plan($map, $source, $destination);
    }

    private function write(string $name, string $source): void
    {
        file_put_contents($this->root . '/src/' . $name, $source);
    }
}
