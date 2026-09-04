<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Cli\CliApplication;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;

final class CliApplicationTest extends TestCase
{
    public function testGeneralHelpIncludesArtifactNoteDiscoveryTemporalAndPlanCapabilitiesOnce(): void
    {
        ob_start();
        try {
            $exit = (new CliApplication())->run(['agent-map', 'help']);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(1, substr_count($output, 'Artifact paths:'));
        self::assertSame(1, substr_count($output, 'Architecture discovery:'));
        self::assertSame(1, substr_count($output, 'Temporal evolution:'));
        self::assertSame(1, substr_count($output, 'Class relocation evidence:'));
        self::assertSame(1, substr_count($output, 'class-move-plan Build a read-only class namespace move plan with PSR-4 destination evidence'));
        self::assertSame(1, substr_count($output, 'Plan capability discovery:'));
        self::assertSame(1, substr_count($output, 'plan-capabilities List every governed rename, removal and move contract'));
        self::assertSame(1, substr_count($output, 'Method refactoring evidence:'));
        self::assertSame(1, substr_count($output, 'Parameter refactoring evidence:'));
        self::assertSame(1, substr_count($output, 'Property refactoring evidence:'));
        self::assertSame(1, substr_count($output, 'Method removal evidence:'));
        self::assertSame(1, substr_count($output, 'Property removal evidence:'));
        self::assertSame(1, substr_count($output, 'Class-constant removal evidence:'));
        self::assertSame(1, substr_count($output, 'class-constant-removal-plan Build an exact unused-private-class-constant deletion plan'));
    }

    public function testPlanCapabilitiesCoverEveryRegisteredPlanRouter(): void
    {
        ob_start();
        try {
            $exit = (new CliApplication())->run(['agent-map', 'plan-capabilities', '--format=json']);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(0, $exit);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('plan_capabilities', $payload['type'] ?? null);
        self::assertSame([
            [
                'family' => 'move',
                'kind' => 'class',
                'command' => 'class-move-plan',
                'plan_type' => 'class_move_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'none',
            ],
            [
                'family' => 'move',
                'kind' => 'method',
                'command' => 'method-move-plan',
                'plan_type' => 'method_move_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'family' => 'removal',
                'kind' => 'class_constant',
                'command' => 'class-constant-removal-plan',
                'plan_type' => 'class_constant_removal_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'family' => 'removal',
                'kind' => 'method',
                'command' => 'method-removal-plan',
                'plan_type' => 'method_removal_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'family' => 'removal',
                'kind' => 'property',
                'command' => 'property-removal-plan',
                'plan_type' => 'property_removal_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'family' => 'rename',
                'kind' => 'class',
                'command' => 'class-rename-plan',
                'plan_type' => 'class_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'none',
            ],
            [
                'family' => 'rename',
                'kind' => 'class_constant',
                'command' => 'class-constant-rename-plan',
                'plan_type' => 'class_constant_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'none',
            ],
            [
                'family' => 'rename',
                'kind' => 'function',
                'command' => 'function-rename-plan',
                'plan_type' => 'function_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'family' => 'rename',
                'kind' => 'method',
                'command' => 'rename-plan',
                'plan_type' => 'method_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'family' => 'rename',
                'kind' => 'parameter',
                'command' => 'parameter-rename-plan',
                'plan_type' => 'parameter_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
            [
                'family' => 'rename',
                'kind' => 'property',
                'command' => 'property-rename-plan',
                'plan_type' => 'property_rename_plan',
                'contract_version' => '1.0',
                'semantic_backend' => 'phpstan',
            ],
        ], $payload['capabilities'] ?? null);
    }

    public function testEveryAdvertisedPlanCommandIsRoutable(): void
    {
        ob_start();
        try {
            $exit = (new CliApplication())->run(['agent-map', 'plan-capabilities', '--format=json']);
        } finally {
            $output = (string) ob_get_clean();
        }
        self::assertSame(0, $exit);

        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['capabilities'] ?? null);

        // Discovery is only useful if the command it advertises actually exists.
        foreach ($payload['capabilities'] as $capability) {
            self::assertIsArray($capability);
            $command = $capability['command'] ?? null;
            self::assertIsString($command);

            $help = '';
            ob_start();
            try {
                $status = (new CliApplication())->run(['agent-map', 'help', $command]);
            } finally {
                $help = (string) ob_get_clean();
            }

            self::assertSame(0, $status, $command);
            self::assertStringContainsString('agent-map ' . $command, $help, $command);
        }
    }

    public function testGeneralAndDiscoveryCommandsShareEmbeddedMapRoot(): void
    {
        $root = sys_get_temp_dir() . '/agent-map-cli-root-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o775, true);
        $source = $root . '/src/Foo.php';
        file_put_contents($source, "<?php\n");
        $hash = hash_file('sha256', $source);
        self::assertIsString($hash);

        $artifacts = MapArtifactPaths::forProject($root, 'var/agent-state/map');
        (new IndexWriter())->write(
            new AgentMapIndex(
                '2.0',
                $root,
                'test',
                [new FileEntry('src/Foo.php', 'sha256:' . $hash, '', [])],
            ),
            $artifacts->indexJson(),
        );

        $application = new CliApplication(projectRoot: $root, mapRoot: 'var/agent-state/map');
        try {
            ob_start();
            $summaryExit = $application->run(['agent-map', 'summary', '--format=json']);
            $summary = (string) ob_get_clean();

            ob_start();
            $discoveryExit = $application->run(['agent-map', 'discover', '--format=json']);
            $discovery = (string) ob_get_clean();
        } finally {
            $this->removeDirectory($root);
        }

        self::assertSame(0, $summaryExit);
        self::assertJson($summary);
        self::assertSame(0, $discoveryExit);
        self::assertJson($discovery);
    }

    public function testProjectRootAloneGivesEveryRouterTheSameStandaloneMount(): void
    {
        $root = sys_get_temp_dir() . '/agent-map-cli-project-root-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o775, true);
        $source = $root . '/src/Foo.php';
        file_put_contents($source, "<?php\n");
        $hash = hash_file('sha256', $source);
        self::assertIsString($hash);

        (new IndexWriter())->write(
            new AgentMapIndex('2.0', $root, 'test', [new FileEntry('src/Foo.php', 'sha256:' . $hash, '', [])]),
            MapArtifactPaths::forProject($root)->indexJson(),
        );

        try {
            ob_start();
            $exit = (new CliApplication(projectRoot: $root))->run(['agent-map', 'discover', '--format=json']);
            $output = (string) ob_get_clean();
        } finally {
            $this->removeDirectory($root);
        }

        self::assertSame(0, $exit);
        self::assertJson($output);
    }

    public function testExplicitSourceRootDoesNotRelocateEmbeddedArtifactRoot(): void
    {
        $projectRoot = sys_get_temp_dir() . '/agent-map-cli-project-' . bin2hex(random_bytes(6));
        $sourceRoot = sys_get_temp_dir() . '/agent-map-cli-source-' . bin2hex(random_bytes(6));
        mkdir($projectRoot . '/src', 0o775, true);
        mkdir($sourceRoot, 0o775, true);
        $source = $projectRoot . '/src/Foo.php';
        file_put_contents($source, "<?php\n");
        $hash = hash_file('sha256', $source);
        self::assertIsString($hash);

        (new IndexWriter())->write(
            new AgentMapIndex('2.0', $projectRoot, 'test', [new FileEntry('src/Foo.php', 'sha256:' . $hash, '', [])]),
            MapArtifactPaths::forProject($projectRoot, 'var/agent-state/map')->indexJson(),
        );

        try {
            ob_start();
            $exit = (new CliApplication(projectRoot: $projectRoot, mapRoot: 'var/agent-state/map'))->run([
                'agent-map',
                'summary',
                '--root=' . $sourceRoot,
                '--format=json',
            ]);
            $output = (string) ob_get_clean();
        } finally {
            $this->removeDirectory($projectRoot);
            $this->removeDirectory($sourceRoot);
        }

        self::assertSame(0, $exit);
        self::assertJson($output);
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
