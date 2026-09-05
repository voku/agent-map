<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Inspect\LocalBindingCheckpoint;
use voku\AgentMap\Inspect\LocalExitCheckpoint;
use voku\AgentMap\Inspect\LocalGuardCheckpoint;
use voku\AgentMap\Inspect\LocalSemanticFrameBuilder;
use voku\AgentMap\Inspect\LocalUseCheckpoint;
use voku\AgentMap\Inspect\ScopeInspector;
use voku\AgentMap\Inspect\ScopeSelector;

final class LocalSemanticFrameTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/agent-map-local-semantics-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/src/Service', 0o775, true);

        file_put_contents($this->fixtureRoot . '/src/Service/Models.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Service;

        final class Mailbox
        {
            public function sync(): void
            {
            }
        }

        final class User
        {
            public function getMailbox(): ?Mailbox
            {
                return new Mailbox();
            }
        }

        final class UserRepository
        {
            /** @return User|false */
            public function find(string $id): User|false
            {
                return new User();
            }
        }
        PHP);

        file_put_contents($this->fixtureRoot . '/src/Service/MailboxSyncService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Service;

        final class MailboxSyncService
        {
            public function syncMailbox(UserRepository $repository, string $id): bool
            {
                $false = true;
                $user = $repository->find($id);
                if ($user === false) {
                    return $false;
                }

                $mailbox = $user->getMailbox();
                if ($mailbox === null) {
                    return false;
                }

                $mailbox->sync();

                return true;
            }
        }
        PHP);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->fixtureRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->fixtureRoot);
        }
    }

    public function testBuildsOrderedLocalSemanticFrameWithBindingsGuardsUsesAndExits(): void
    {
        $index = (new AgentMapBuilder())->build($this->fixtureRoot, ['src'], []);
        $selector = new ScopeSelector();
        $selection = $selector->select($index, 'Demo\Service\MailboxSyncService::syncMailbox');

        self::assertNotNull($selection->target);
        $target = $selection->target;

        $builder = new LocalSemanticFrameBuilder();
        $frame = $builder->build($index, $target);

        self::assertSame('method:Demo\Service\MailboxSyncService::syncMailbox', $frame->targetId);
        self::assertSame('src/Service/MailboxSyncService.php', $frame->file);
        self::assertCount(7, $frame->checkpoints);

        // Checkpoint 1: $false = true
        $cp1 = $frame->checkpoints[0];
        self::assertInstanceOf(LocalBindingCheckpoint::class, $cp1);
        self::assertSame('$false', $cp1->variable);
        self::assertSame('literal', $cp1->expressionKind);
        self::assertContains($cp1->resolvedType, ['true', 'bool']);
        self::assertSame('true', $cp1->literalValue);
        self::assertStringContainsString('$false = true', $cp1->codeSnippet);

        // Checkpoint 2: $user = $repository->find($id)
        $cp2 = $frame->checkpoints[1];
        self::assertInstanceOf(LocalBindingCheckpoint::class, $cp2);
        self::assertSame('$user', $cp2->variable);
        self::assertSame('method_call', $cp2->expressionKind);
        self::assertStringContainsString('User', $cp2->resolvedType);
        self::assertStringContainsString('false', $cp2->resolvedType);
        self::assertStringContainsString('UserRepository', (string) $cp2->receiverType);
        self::assertSame('method:Demo\Service\UserRepository::find', $cp2->callTarget);

        // Checkpoint 3: guard on $user === false
        $cp3 = $frame->checkpoints[2];
        self::assertInstanceOf(LocalGuardCheckpoint::class, $cp3);
        self::assertStringContainsString('$user === false', $cp3->condition);
        self::assertTrue($cp3->exits);
        self::assertSame('return', $cp3->exitKind);
        self::assertSame('$false', $cp3->exitTarget);
        self::assertSame('excludes false', $cp3->narrowing);

        // Checkpoint 4: $mailbox = $user->getMailbox()
        $cp4 = $frame->checkpoints[3];
        self::assertInstanceOf(LocalBindingCheckpoint::class, $cp4);
        self::assertSame('$mailbox', $cp4->variable);
        self::assertSame('method_call', $cp4->expressionKind);
        self::assertStringContainsString('Mailbox', $cp4->resolvedType);
        self::assertStringContainsString('User', (string) $cp4->receiverType);

        // Checkpoint 5: guard on $mailbox === null
        $cp5 = $frame->checkpoints[4];
        self::assertInstanceOf(LocalGuardCheckpoint::class, $cp5);
        self::assertStringContainsString('$mailbox === null', $cp5->condition);
        self::assertTrue($cp5->exits);
        self::assertSame('return', $cp5->exitKind);
        self::assertSame('false', $cp5->exitTarget);
        self::assertSame('excludes null', $cp5->narrowing);

        // Checkpoint 6: semantic use: $mailbox->sync()
        $cp6 = $frame->checkpoints[5];
        self::assertInstanceOf(LocalUseCheckpoint::class, $cp6);
        self::assertSame('$mailbox', $cp6->variable);
        self::assertStringContainsString('$mailbox->sync()', $cp6->expression);
        self::assertStringContainsString('Mailbox', (string) $cp6->receiverType);

        // Checkpoint 7: exit: return true
        $cp7 = $frame->checkpoints[6];
        self::assertInstanceOf(LocalExitCheckpoint::class, $cp7);
        self::assertSame('return', $cp7->exitKind);
        self::assertStringContainsString('true', $cp7->expressionType);

        // toText formatting
        $text = $frame->toText();
        self::assertStringContainsString('Flow for method:Demo\Service\MailboxSyncService::syncMailbox', $text);
        self::assertStringContainsString('$false = true', $text);
        self::assertStringContainsString('$user = $repository->find($id)', $text);
        self::assertStringContainsString('guard on $user === false [exits: return $false, narrows: excludes false]', $text);
        self::assertStringContainsString('$mailbox = $user->getMailbox()', $text);
        self::assertStringContainsString('guard on $mailbox === null [exits: return false, narrows: excludes null]', $text);
        self::assertStringContainsString('semantic use: $mailbox->sync() [receiver: Demo\Service\Mailbox', $text);
        self::assertStringContainsString('exit: return true', $text);

        // ScopeInspector integration
        $inspection = (new ScopeInspector())->inspect($index, $target);
        self::assertNotNull($inspection->localSemantics);
        self::assertCount(7, $inspection->localSemantics->checkpoints);
        $array = $inspection->toArray();
        self::assertArrayHasKey('local_semantics', $array);
        self::assertNotNull($array['local_semantics']);
        self::assertCount(7, $array['local_semantics']['checkpoints']);

        // EditContextPlanner integration
        $plan = (new EditContextPlanner())->plan($index, 'Demo\Service\MailboxSyncService::syncMailbox');
        self::assertNotNull($plan->localSemantics);
        self::assertCount(7, $plan->localSemantics->checkpoints);
        $planArray = $plan->toArray();
        self::assertArrayHasKey('local_semantics', $planArray);
        self::assertNotNull($planArray['local_semantics']);
    }
}
