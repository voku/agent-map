<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Scaffold\MethodScaffoldPlan;
use voku\AgentMap\Scaffold\MethodScaffoldPlanner;

final class MethodScaffoldPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-method-scaffold-' . bin2hex(random_bytes(6));
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

    public function testPlansSafeMethodScaffold(): void
    {
        $this->write('Calculator.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Math;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            target: 'App\Math\Calculator::subtract',
            visibility: 'public',
            static: false,
            parameters: ['int $a', 'int $b'],
            returnType: 'int',
            docSummary: 'Subtract b from a.',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('App\Math\Calculator', $plan->targetClass);
        self::assertSame('subtract', $plan->methodName);
        self::assertCount(1, $plan->edits);

        $edit = $plan->edits[0];
        self::assertSame('method_declaration_insertion', $edit->role);
        self::assertStringContainsString('public function subtract(int $a, int $b): int', $edit->replacement);
        self::assertStringContainsString('* Subtract b from a.', $edit->replacement);
    }

    public function testInsertsUseStatementsForExternalTypes(): void
    {
        $this->write('UserService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

final class UserService
{
    public function find(int $id): ?User
    {
        return null;
    }
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            target: 'App\Service\UserService::logAction',
            parameters: ['User $user', 'DateTimeImmutable $at'],
            returnType: 'void',
            docSummary: 'Record action timestamp.',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        // DateTimeImmutable is external and should get a use statement edit
        self::assertCount(2, $plan->edits);
        self::assertSame('use_statement_insertion', $plan->edits[0]->role);
        self::assertStringContainsString('use DateTimeImmutable;', $plan->edits[0]->replacement);
        self::assertSame('method_declaration_insertion', $plan->edits[1]->role);
    }

    public function testBlocksWhenMethodAlreadyExists(): void
    {
        $this->write('Existing.php', <<<'PHP'
<?php

declare(strict_types=1);

class Existing
{
    public function alreadyHere(): void
    {
    }
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan($map, 'Existing::alreadyHere');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertNotEmpty($plan->blockers);
        self::assertStringContainsString('already exists', $plan->blockers[0]);
    }

    public function testBlocksWhenTargetNotFound(): void
    {
        $this->write('Empty.php', "<?php\n\nclass SomeClass\n{\n}\n");
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan($map, 'NonExistent::foo');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('Target class not found in index', $plan->blockers[0]);
    }

    public function testGeneratedMethodFollowsALfSpaceFileExactlyWithoutABlankLineBeforeTheClosingBrace(): void
    {
        $source = "<?php\n\nfinal class Box\n{\n    public function one(): int\n    {\n        return 1;\n    }\n}\n";

        self::assertSame(
            "<?php\n\nfinal class Box\n{\n    public function one(): int\n    {\n        return 1;\n    }\n\n    /**\n     * Doubles.\n     *\n     * @param int \$x\n     * @return int\n     */\n    public function double(int \$x): int\n    {\n        return \$x * 2;\n    }\n}\n",
            $this->scaffold($source),
        );
    }

    public function testGeneratedMethodUsesCrlfInACrlfFile(): void
    {
        $source = "<?php\r\n\r\nfinal class Box\r\n{\r\n    public function one(): int\r\n    {\r\n        return 1;\r\n    }\r\n}\r\n";

        $result = $this->scaffold($source);

        self::assertSame(substr_count($result, "\n"), substr_count($result, "\r\n"), 'every line break is CRLF');
        self::assertStringContainsString("    }\r\n\r\n    /**\r\n     * Doubles.\r\n", $result);
    }

    public function testGeneratedMethodUsesTabsInATabIndentedFile(): void
    {
        $source = "<?php\n\nfinal class Box\n{\n\tpublic function one(): int\n\t{\n\t\treturn 1;\n\t}\n}\n";

        self::assertSame(
            "<?php\n\nfinal class Box\n{\n\tpublic function one(): int\n\t{\n\t\treturn 1;\n\t}\n\n\t/**\n\t * Doubles.\n\t *\n\t * @param int \$x\n\t * @return int\n\t */\n\tpublic function double(int \$x): int\n\t{\n\t\treturn \$x * 2;\n\t}\n}\n",
            $this->scaffold($source),
        );
    }

    public function testAnImportedTypeIsInsertedWithTheFilesLineEnding(): void
    {
        $source = "<?php\r\n\r\nnamespace App;\r\n\r\nuse Foo\\Bar;\r\n\r\nfinal class Box\r\n{\r\n}\r\n";
        $this->write('Box.php', $source);

        $plan = (new MethodScaffoldPlanner($this->root))->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'App\\Box::make',
            parameters: ['\\Other\\Thing $thing'],
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        $result = $this->applyEdits($source, $plan->edits);
        self::assertSame(substr_count($result, "\n"), substr_count($result, "\r\n"));
        self::assertStringContainsString("use Foo\\Bar;\r\nuse Other\\Thing;\r\n", $result);
    }

    /** Scaffolds `double(int $x): int` on Box and returns the destination file after applying the planned edits. */
    private function scaffold(string $source): string
    {
        $this->write('Box.php', $source);
        $plan = (new MethodScaffoldPlanner($this->root))->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Box::double',
            parameters: ['int $x'],
            returnType: 'int',
            docSummary: 'Doubles.',
            body: 'return $x * 2;',
        );
        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));

        return $this->applyEdits($source, $plan->edits);
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

    private function write(string $name, string $source): void
    {
        file_put_contents($this->root . '/src/' . $name, $source);
    }
}
