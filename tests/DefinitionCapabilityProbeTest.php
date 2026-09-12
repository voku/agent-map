<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Cli\CliApplication;
use voku\AgentMap\Evidence\DefinitionCapabilityProbe;

final class DefinitionCapabilityProbeTest extends TestCase
{
    private string $root;
    private string $bin;
    private string $originalPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-definition-capabilities-' . bin2hex(random_bytes(6));
        $this->bin = $this->root . '/bin';
        mkdir($this->bin, 0o775, true);
        $path = getenv('PATH');
        $this->originalPath = is_string($path) ? $path : '';
        putenv('PATH=' . $this->bin);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        $this->removeDirectory($this->root);
    }

    public function testJavaScriptCapabilityIsOperationalAndCliProjectsSameOwnerRoute(): void
    {
        file_put_contents($this->root . '/package.json', "{}\n");
        $this->writeExecutable('scip');
        $this->writeExecutable('scip-typescript');

        $report = (new DefinitionCapabilityProbe())->probe(
            $this->root,
            $this->root . '/.agent-map/php-symbols.json',
        );

        self::assertSame('javascript_typescript', $report->route);
        self::assertSame('operational', $report->javascriptTypescript->status);
        self::assertSame('unavailable', $report->php->status);
        self::assertTrue($report->routesToPolyglotDefinition());

        ob_start();
        $exit = (new CliApplication())->run([
            'agent-map',
            'definition-capabilities',
            '--root=' . $this->root,
            '--format=json',
        ]);
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame('definition_capabilities', $payload['type'] ?? null);
        self::assertSame($report->route, $payload['route'] ?? null);
        self::assertSame('operational', $payload['capabilities']['javascript_typescript']['status'] ?? null);
    }

    public function testMissingIndexerIsUnavailableAndProjectsSafeManualRecovery(): void
    {
        file_put_contents($this->root . '/package.json', "{}\n");
        $this->writeExecutable('scip');

        $report = (new DefinitionCapabilityProbe())->probe(
            $this->root,
            $this->root . '/.agent-map/php-symbols.json',
        );

        self::assertSame('javascript_typescript', $report->route);
        self::assertSame('unavailable', $report->javascriptTypescript->status);
        self::assertSame('Missing executable(s): scip-typescript.', $report->javascriptTypescript->reason);
        self::assertSame(['scip-typescript'], $report->javascriptTypescript->missingExecutables);
        self::assertSame('manual_setup_required', $report->javascriptTypescript->nextAction);
        self::assertTrue($report->routesToPolyglotDefinition());

        ob_start();
        $exit = (new CliApplication())->run([
            'agent-map',
            'definition-capabilities',
            '--root=' . $this->root,
            '--format=json',
        ]);
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame(['scip-typescript'], $payload['capabilities']['javascript_typescript']['missing_executables'] ?? null);
        self::assertSame('manual_setup_required', $payload['capabilities']['javascript_typescript']['next_action'] ?? null);
    }

    public function testMixedProjectIsExplicitAndNeverGuessedOperational(): void
    {
        file_put_contents($this->root . '/package.json', "{}\n");
        file_put_contents($this->root . '/pyproject.toml', "[project]\nname = \"mixed\"\n");
        $this->writeExecutable('scip');
        $this->writeExecutable('scip-typescript');
        $this->writeExecutable('scip-python');

        $report = (new DefinitionCapabilityProbe())->probe(
            $this->root,
            $this->root . '/.agent-map/php-symbols.json',
        );

        self::assertSame('mixed', $report->route);
        self::assertSame('unavailable', $report->javascriptTypescript->status);
        self::assertSame('unavailable', $report->python->status);
        self::assertTrue($report->routesToPolyglotDefinition());
    }

    public function testExistingPhpMapRemainsTheDefinitionOwner(): void
    {
        mkdir($this->root . '/.agent-map', 0o775, true);
        file_put_contents($this->root . '/.agent-map/php-symbols.json', "{}\n");
        file_put_contents($this->root . '/package.json', "{}\n");
        $this->writeExecutable('scip');
        $this->writeExecutable('scip-typescript');

        $report = (new DefinitionCapabilityProbe())->probe(
            $this->root,
            $this->root . '/.agent-map/php-symbols.json',
        );

        self::assertSame('php', $report->route);
        self::assertSame('operational', $report->php->status);
        self::assertSame('unavailable', $report->javascriptTypescript->status);
        self::assertFalse($report->routesToPolyglotDefinition());
    }

    private function writeExecutable(string $name): void
    {
        $path = $this->bin . '/' . $name;
        if (file_put_contents($path, "#!/bin/sh\nexit 0\n") === false || !chmod($path, 0o775)) {
            throw new RuntimeException('Unable to create fake executable: ' . $name);
        }
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
