<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Rename\ClassRenamePlan;
use voku\AgentMap\Rename\ClassRenamePlanner;
use voku\AgentMap\Rename\RenameEdit;

/** End-to-end proof that class rename planning is backend-independent for static PHP names. */
final class ClassRenameDogfoodTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Class rename parity dogfood requires the optional phpstan/phpstan capability.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-class-rename-dogfood-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        file_put_contents($this->root . '/src/OldClass.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace ClassRenameDogfood;

final class OldClass
{
    public static function create(): self
    {
        return new self();
    }
}
PHP);
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace ClassRenameDogfood;

final class Consumer
{
    public function make(OldClass $value): OldClass
    {
        return new OldClass();
    }

    public function className(): string
    {
        return OldClass::class;
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

    public function testRealStructuralAndPhpStanBuildsProjectSameExactClassRename(): void
    {
        $structural = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );
        $phpStan = (new AgentMapBuilder())->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );

        self::assertSame('simple-php-code-parser+structural-only', $structural->backend);
        self::assertSame('simple-php-code-parser+phpstan', $phpStan->backend);

        $planner = new ClassRenamePlanner();
        $structuralPlan = $planner->plan($structural, 'ClassRenameDogfood\\OldClass', 'NewClass');
        $phpStanPlan = $planner->plan($phpStan, 'ClassRenameDogfood\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_SAFE, $structuralPlan->status, implode("\n", $structuralPlan->blockers));
        self::assertSame(ClassRenamePlan::STATUS_SAFE, $phpStanPlan->status, implode("\n", $phpStanPlan->blockers));
        self::assertCount(5, $structuralPlan->edits);
        self::assertCount(5, $phpStanPlan->edits);
        self::assertCount(1, $structuralPlan->moves);
        self::assertCount(1, $phpStanPlan->moves);
        self::assertTrue($structuralPlan->moves[0]->destinationMustBeAbsent);
        self::assertTrue($phpStanPlan->moves[0]->destinationMustBeAbsent);
        self::assertTrue($structuralPlan->moves[0]->toArray()['destination_must_be_absent']);
        self::assertSame($this->editProjection($structuralPlan->edits), $this->editProjection($phpStanPlan->edits));
        self::assertSame($structuralPlan->moves[0]->toPath, $phpStanPlan->moves[0]->toPath);
    }

    /**
     * @param list<RenameEdit> $edits
     * @return list<array{path: string, start: int, end: int, expected: string, replacement: string, role: string}>
     */
    private function editProjection(array $edits): array
    {
        return array_map(
            static fn (RenameEdit $edit): array => [
                'path' => $edit->path,
                'start' => $edit->startFilePos,
                'end' => $edit->endFilePos,
                'expected' => $edit->expected,
                'replacement' => $edit->replacement,
                'role' => $edit->role,
            ],
            $edits,
        );
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
