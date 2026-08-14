<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\CliApplication;

final class CliApplicationTest extends TestCase
{
    public function testGeneralHelpIncludesDiscoveryAndTemporalCommandsOnce(): void
    {
        ob_start();
        try {
            $exit = (new CliApplication())->run(['agent-map', 'help']);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(1, substr_count($output, 'Architecture discovery:'));
        self::assertSame(1, substr_count($output, 'Temporal evolution:'));
    }
}
