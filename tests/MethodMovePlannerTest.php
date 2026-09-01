<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Move\MethodMovePlan;
use voku\AgentMap\Move\MethodMovePlanner;

/**
 * The fixtures are reduced from real IT-Portal code so the first slice is proven
 * against shapes that actually occur: RollenAdGroupsCronJob::diffMembers() is a
 * genuinely owner-independent public static helper, while execute() in the same
 * class reads self:: constants and self:: helpers.
 */
final class MethodMovePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Method move tests require PHPStan.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-method-move-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPlansRemovalAndInsertionForAnOwnerIndependentStaticMethod(): void
    {
        $this->writeCronJob();
        $this->write('Diff.php', <<<'PHP'
<?php
final class Diff
{
}
PHP);

        $plan = $this->plan('RollenAdGroupsCronJob::diffMembers', 'Diff');

        self::assertSame(MethodMovePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertSame([], $plan->ownerDependencies);
        // execute() calls self::diffMembers(), so the relocation must also
        // re-point that call: leaving it as self:: would break after the move.
        self::assertSame(
            ['method_declaration_removal', 'method_declaration_insertion', 'static_call_owner_rewrite'],
            array_map(static fn ($edit): string => $edit->role, $plan->edits),
        );

        [$removal, $insertion, $callSite] = $plan->edits;
        self::assertSame('self', $callSite->expected);
        self::assertSame('\\Diff', $callSite->replacement);
        self::assertStringContainsString('function diffMembers', $removal->expected);
        self::assertSame('', $removal->replacement);
        self::assertStringContainsString('function diffMembers', $insertion->replacement);
        // The insertion keeps the anchor it replaces, so the class still closes.
        self::assertNotSame('', $insertion->expected);
        self::assertTrue(str_ends_with($insertion->replacement, $insertion->expected));
        self::assertSame('src/Diff.php', $insertion->path);

        // Planning is read-only.
        self::assertStringContainsString('function diffMembers', (string) file_get_contents($this->root . '/src/RollenAdGroupsCronJob.php'));
        self::assertStringNotContainsString('diffMembers', (string) file_get_contents($this->root . '/src/Diff.php'));
    }

    public function testPublicSourceIsReviewRequiredRatherThanSafe(): void
    {
        $this->writeCronJob();
        $this->write('Diff.php', "<?php\nfinal class Diff\n{\n}\n");

        $plan = $this->plan('RollenAdGroupsCronJob::diffMembers', 'Diff');

        $codes = array_map(static fn ($spot): string => $spot->kind, $plan->blindSpots);
        self::assertContains('out_of_scope_api_exposure', $codes);
    }

    public function testOwnerSensitiveBodyCannotProduceASafePlan(): void
    {
        $this->writeCronJob();
        $this->write('Diff.php', "<?php\nfinal class Diff\n{\n}\n");

        $plan = $this->plan('RollenAdGroupsCronJob::execute', 'Diff');

        self::assertSame(MethodMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertContains('self::', $plan->ownerDependencies);
    }

    /**
     * From IT-Portal's Authentication::normalizeRemoteUserToAdAccount, which the
     * planner moved with status SAFE while re-pointing its caller at the new
     * owner. The result parses and then fatals with "Call to private method
     * ... from scope ...", because a move may not widen visibility.
     */
    public function testMovingANonPublicMethodWithCallersBlocks(): void
    {
        $source = <<<'PHP'
<?php
final class Authentication
{
    public static function run(string $value): string
    {
        return self::normalize($value);
    }

    private static function normalize(string $value): string
    {
        return trim($value);
    }
}
PHP;
        $this->write('Authentication.php', $source);
        $this->write('Diff.php', "<?php\nfinal class Diff\n{\n}\n");

        $plan = $this->plan('Authentication::normalize', 'Diff');

        self::assertSame(MethodMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertNotEmpty(array_filter(
            $plan->blockers,
            static fn (string $blocker): bool => str_contains($blocker, 'may not silently widen visibility'),
        ));
    }

    public function testMovingANonPublicMethodWithoutCallersIsStillPlannable(): void
    {
        $source = <<<'PHP'
<?php
final class Lonely
{
    private static function unusedHelper(string $value): string
    {
        return trim($value);
    }
}
PHP;
        $this->write('Lonely.php', $source);
        $this->write('Diff.php', "<?php\nfinal class Diff\n{\n}\n");

        $plan = $this->plan('Lonely::unusedHelper', 'Diff');

        self::assertNotSame(MethodMovePlan::STATUS_BLOCKED, $plan->status, implode("\n", $plan->blockers));
        self::assertNotSame([], $plan->edits);
    }

    public function testDestinationCollisionBlocks(): void
    {
        $this->writeCronJob();
        $this->write('Diff.php', <<<'PHP'
<?php
final class Diff
{
    public static function diffMembers(array $soll, array $ist): array
    {
        return [];
    }
}
PHP);

        $plan = $this->plan('RollenAdGroupsCronJob::diffMembers', 'Diff');

        self::assertSame(MethodMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertNotEmpty(array_filter($plan->blockers, static fn (string $b): bool => str_contains($b, 'already declares')));
    }

    public function testInstanceMethodIsOutsideTheFirstSlice(): void
    {
        $this->writeCronJob();
        $this->write('Diff.php', "<?php\nfinal class Diff\n{\n}\n");

        $plan = $this->plan('RollenAdGroupsCronJob::instanceHelper', 'Diff');

        self::assertSame(MethodMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertNotEmpty(array_filter($plan->blockers, static fn (string $b): bool => str_contains($b, 'static methods only')));
    }

    public function testUnknownDestinationBlocks(): void
    {
        $this->writeCronJob();

        $plan = $this->plan('RollenAdGroupsCronJob::diffMembers', 'DoesNotExist');

        self::assertSame(MethodMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertNotEmpty(array_filter($plan->blockers, static fn (string $b): bool => str_contains($b, 'not present in the map')));
    }

    public function testMovingIntoTheCurrentOwnerBlocks(): void
    {
        $this->writeCronJob();

        $plan = $this->plan('RollenAdGroupsCronJob::diffMembers', 'RollenAdGroupsCronJob');

        self::assertSame(MethodMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertNotEmpty(array_filter($plan->blockers, static fn (string $b): bool => str_contains($b, 'nothing to relocate')));
    }

    public function testJsonProjectionCarriesTheContractIdentity(): void
    {
        $this->writeCronJob();
        $this->write('Diff.php', "<?php\nfinal class Diff\n{\n}\n");

        $array = $this->plan('RollenAdGroupsCronJob::diffMembers', 'Diff')->toArray();

        self::assertSame('method_move_plan', $array['type']);
        self::assertSame('1.0', $array['contract_version']);
        self::assertSame('Diff', $array['destination_fqn']);
        self::assertArrayHasKey('owner_dependencies', $array);
        self::assertArrayHasKey('not_observable', $array);
    }

    private function plan(string $target, string $destination): MethodMovePlan
    {
        return (new MethodMovePlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            $target,
            $destination,
        );
    }

    private function write(string $name, string $source): void
    {
        file_put_contents($this->root . '/src/' . $name, $source);
    }

    /** Reduced from modules/activedirectory/lib/RollenAdGroupsCronJob.php. */
    private function writeCronJob(): void
    {
        $this->write('RollenAdGroupsCronJob.php', <<<'PHP'
<?php
final class RollenAdGroupsCronJob
{
    private const PROGRAM = 'check_ad_role_group_member.php';

    /**
     * Separates the soll/ist decision from the AD binding so it stays testable.
     *
     * @param array<string, int> $soll_members
     * @param array<string, null|int> $ist_members
     * @return array{missing: list<string>, surplus: list<string>}
     */
    public static function diffMembers(array $soll_members, array $ist_members): array
    {
        $missing = [];
        foreach ($soll_members as $account => $ma_id) {
            if (!array_key_exists($account, $ist_members)) {
                $missing[] = $account;
            }
        }

        return ['missing' => $missing, 'surplus' => []];
    }

    public static function execute(): void
    {
        $diff = self::diffMembers([], []);
        echo self::PROGRAM . count($diff['missing']);
    }

    public function instanceHelper(): string
    {
        return 'not static';
    }
}
PHP);
    }
}
