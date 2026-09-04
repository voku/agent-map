<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\ClassScaffoldCliApplication;
use voku\AgentMap\Cli\CliApplication;
use voku\AgentMap\Cli\MethodCopyCliApplication;
use voku\AgentMap\Cli\MethodScaffoldCliApplication;
use voku\AgentMap\Plan\PlanCapability;

final class ScaffoldCliTest extends TestCase
{
    public function testCliHelpFlags(): void
    {
        $copyCli = new MethodCopyCliApplication();
        self::assertTrue($copyCli->supports(['agent-map', 'method-copy-plan']));
        self::assertTrue($copyCli->supports(['agent-map', 'help', 'method-copy-plan']));

        $scaffoldCli = new MethodScaffoldCliApplication();
        self::assertTrue($scaffoldCli->supports(['agent-map', 'method-scaffold-plan']));
        self::assertTrue($scaffoldCli->supports(['agent-map', 'help', 'method-scaffold-plan']));

        $classCli = new ClassScaffoldCliApplication();
        self::assertTrue($classCli->supports(['agent-map', 'class-scaffold-plan']));
        self::assertTrue($classCli->supports(['agent-map', 'help', 'class-scaffold-plan']));
    }

    public function testCapabilitiesExposedInMainCli(): void
    {
        $app = new CliApplication();
        ob_start();
        $code = $app->run(['agent-map', 'plan-capabilities', '--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        $capabilities = $decoded['capabilities'] ?? [];
        self::assertIsArray($capabilities);

        $commands = array_column($capabilities, 'command');
        self::assertContains('method-copy-plan', $commands);
        self::assertContains('method-scaffold-plan', $commands);
        self::assertContains('class-scaffold-plan', $commands);

        $families = array_column($capabilities, 'family');
        self::assertContains(PlanCapability::FAMILY_COPY, $families);
        self::assertContains(PlanCapability::FAMILY_SCAFFOLD, $families);
    }
}
