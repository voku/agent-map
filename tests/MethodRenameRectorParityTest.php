<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Rename\MethodRenameDefinition;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;
use voku\AgentMap\Rename\RenameEdit;

/**
 * Focused regression scenarios adapted from Rector's method-rename coverage:
 * - rectorphp/rector-src/rules-tests/Renaming/Rector/MethodCall/RenameMethodRector/
 *   Fixture/rename_nullsafe_method_call.php.inc@83496b5d65035792e7b8eea3bb083e8f3b16bec0
 * - rectorphp/rector/rules/Renaming/Rector/MethodCall/
 *   RenameMethodRector.php@29ac8eb5d206c9d62486c9e8ff018b27f94f34ce
 *
 * The fixtures are reduced to agent-map's evidence boundary: semantic PHPStan relations must still
 * map to exact source tokens, while unsupported or ambiguous dispatch remains fail-closed.
 *
 * Copyright (c) 2017-present Tomáš Votruba.
 * Licensed under the MIT License; see THIRD_PARTY_NOTICES.md.
 */
final class MethodRenameRectorParityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Rector-derived method rename regressions require the optional phpstan/phpstan capability.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-rector-method-rename-' . bin2hex(random_bytes(6));
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

    /** Nullsafe calls retain the same semantic target and therefore receive exact rename edits. */
    public function testPlansNullsafeCallsFromRealPhpStanEvidence(): void
    {
        $this->write('src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace RectorParity;

final class Service
{
    public function oldName(): void
    {
    }
}
PHP);
        $this->write('src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace RectorParity;

final class Caller
{
    public function run(?Service $service): void
    {
        $service?->oldName();
    }
}
PHP);

        $map = $this->map();
        self::assertNotSame([], $map->incoming('method:RectorParity\\Service::oldName', 'calls'));

        $plan = $this->plan($map, new MethodRenameDefinition('RectorParity\\Service', 'oldName', 'renamed'));

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame(['call', 'declaration'], $this->roles($plan));
        self::assertSame(['oldName', 'oldName'], $this->expectedTokens($plan));
    }

    /** Static calls are rewritten only when PHPStan resolves them to the selected declaring method. */
    public function testPlansStaticCallsFromRealPhpStanEvidence(): void
    {
        $this->write('src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace RectorParity;

final class Service
{
    public static function oldName(): void
    {
    }
}
PHP);
        $this->write('src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace RectorParity;

final class Caller
{
    public function run(): void
    {
        Service::oldName();
    }
}
PHP);

        $map = $this->map();
        self::assertNotSame([], $map->incoming('method:RectorParity\\Service::oldName', 'calls'));

        $plan = $this->plan($map, MethodRenameDefinition::fromTarget('RectorParity\\Service::oldName', 'renamed'));

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame(['call', 'declaration'], $this->roles($plan));
        self::assertSame(['oldName', 'oldName'], $this->expectedTokens($plan));
    }

    private function plan(AgentMapIndex $map, MethodRenameDefinition $rename): MethodRenamePlan
    {
        return (new MethodRenamePlanner())->plan($map, $rename->target(), $rename->newMethod);
    }

    /** @return list<string> */
    private function roles(MethodRenamePlan $plan): array
    {
        $roles = array_map(static fn (RenameEdit $edit): string => $edit->role, $plan->edits);
        sort($roles, SORT_STRING);

        return $roles;
    }

    /** @return list<string> */
    private function expectedTokens(MethodRenamePlan $plan): array
    {
        return array_map(static fn (RenameEdit $edit): string => $edit->expected, $plan->edits);
    }

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );
    }

    private function write(string $path, string $content): void
    {
        file_put_contents($this->root . '/' . $path, $content);
    }
}
