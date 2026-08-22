<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RenameCapabilitiesTest extends TestCase
{
    public function testCliPublishesRegisteredRenameCapabilitiesAsMachineReadableContract(): void
    {
        [$exit, $stdout, $stderr] = $this->executeAgentMap(['rename-capabilities', '--format=json']);

        self::assertSame(0, $exit, $stderr);
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('rename_capabilities', $payload['type'] ?? null);
        self::assertSame([
            [
                'kind' => 'function',
                'command' => 'function-rename-plan',
                'plan_type' => 'function_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'kind' => 'method',
                'command' => 'rename-plan',
                'plan_type' => 'method_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
        ], $payload['capabilities'] ?? null);
    }

    public function testGeneralHelpPointsToCapabilityDiscovery(): void
    {
        [$exit, $stdout, $stderr] = $this->executeAgentMap(['help']);

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('rename-capabilities', $stdout);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function executeAgentMap(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-map', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-map.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to read agent-map capability output.');
        }

        return [$exit, $stdout, $stderr];
    }
}
