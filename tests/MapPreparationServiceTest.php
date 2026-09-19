<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationException;
use voku\AgentMap\Prepare\MapPreparationService;

final class MapPreparationServiceTest extends TestCase
{
    private string $root;

    private string $index;

    private MapArtifactPaths $artifacts;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-preparation-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', $this->source('Foo', 'run'));

        $this->index = $this->root . '/map.json';
        $this->artifacts = MapArtifactPaths::forProject($this->root, '.agent-map');
        $builder = new AgentMapBuilder(
            semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
            artifacts: $this->artifacts,
        );
        (new IndexWriter())->write($builder->build($this->root, ['src'], []), $this->index);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCurrentMapIsANoOp(): void
    {
        $before = (string) file_get_contents($this->index);

        $result = (new MapPreparationService())->refresh($this->request());

        self::assertFalse($result->mutated);
        self::assertSame(0, $result->changedFiles);
        self::assertSame(0, $result->removedFiles);
        self::assertSame($before, (string) file_get_contents($this->index));
        self::assertStringContainsString('Index is up to date', $result->message);
    }

    public function testChangedAndNewFilesConvergeThroughOwnerService(): void
    {
        file_put_contents($this->root . '/src/Foo.php', $this->source('Foo', 'changed'));
        file_put_contents($this->root . '/src/Bar.php', $this->source('Bar', 'added'));

        $result = (new MapPreparationService())->refresh($this->request());

        self::assertTrue($result->mutated);
        self::assertSame(2, $result->changedFiles);
        self::assertSame(0, $result->removedFiles);

        $index = (new IndexReader())->read($this->index);
        $paths = array_map(static fn ($file): string => $file->path, $index->files);
        sort($paths);
        self::assertSame(['src/Bar.php', 'src/Foo.php'], $paths);
        self::assertNotNull($index->file('src/Foo.php'));
    }

    public function testRemovalOnlyRefreshPrunesMissingFileWithoutRebuild(): void
    {
        file_put_contents($this->root . '/src/Bar.php', $this->source('Bar', 'keep'));
        $builder = new AgentMapBuilder(
            semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
            artifacts: $this->artifacts,
        );
        (new IndexWriter())->write($builder->build($this->root, ['src'], []), $this->index);
        unlink($this->root . '/src/Foo.php');

        $result = (new MapPreparationService())->refresh($this->request());

        self::assertTrue($result->mutated);
        self::assertSame(0, $result->changedFiles);
        self::assertSame(1, $result->removedFiles);

        $index = (new IndexReader())->read($this->index);
        self::assertNull($index->file('src/Foo.php'));
        self::assertNotNull($index->file('src/Bar.php'));
    }

    public function testPrepareBuildsMissingMapWithinRequestedScope(): void
    {
        unlink($this->index);

        $result = (new MapPreparationService())->prepare($this->request());

        self::assertTrue($result->mutated);
        self::assertSame(1, $result->changedFiles);
        self::assertFileExists($this->index);
        self::assertNotNull((new IndexReader())->read($this->index)->file('src/Foo.php'));
    }

    public function testPrepareReusesRecordedBackendWhenAutoIsRequested(): void
    {
        file_put_contents($this->root . '/src/Foo.php', $this->source('Foo', 'changed'));
        $request = $this->requestWith(backend: 'auto');

        $result = (new MapPreparationService())->prepare($request);

        self::assertTrue($result->mutated);
        self::assertSame('simple-php-code-parser+structural-only', $result->index->backend);
        self::assertNotNull($result->index->file('src/Foo.php'));
    }

    public function testPrepareReportsUnreproducibleBackendWithoutReplacingTheIndex(): void
    {
        $index = (new IndexReader())->read($this->index);
        (new IndexWriter())->write(new \voku\AgentMap\Index\AgentMapIndex(
            schemaVersion: $index->schemaVersion,
            root: $index->root,
            backend: 'external-provider',
            files: $index->files,
            relations: $index->relations,
            diagnostics: $index->diagnostics,
            fingerprint: $index->fingerprint,
        ), $this->index);
        $before = (string) file_get_contents($this->index);

        try {
            (new MapPreparationService())->prepare($this->requestWith(backend: 'auto'));
            self::fail('Expected an unreproducible backend refusal.');
        } catch (MapPreparationException $exception) {
            self::assertSame('backend_unavailable', $exception->reason);
            self::assertStringContainsString('agent-map build', $exception->recoveryCommand);
        }

        self::assertSame($before, (string) file_get_contents($this->index));
    }

    public function testPrepareReportsAnInvalidMapWithoutReplacingIt(): void
    {
        file_put_contents($this->index, '{not a map');
        $before = (string) file_get_contents($this->index);

        try {
            (new MapPreparationService())->prepare($this->requestWith(backend: 'auto'));
            self::fail('Expected an invalid map refusal.');
        } catch (MapPreparationException $exception) {
            self::assertSame('invalid_index', $exception->reason);
            self::assertStringContainsString('agent-map build', $exception->recoveryCommand);
        }

        self::assertSame($before, (string) file_get_contents($this->index));
    }

    private function request(): MapPreparationRequest
    {
        return $this->requestWith();
    }

    /** @param 'auto'|'phpstan'|'structural' $backend */
    private function requestWith(string $backend = 'structural'): MapPreparationRequest
    {
        return new MapPreparationRequest(
            root: $this->root,
            indexPath: $this->index,
            outputPath: $this->index,
            format: 'json',
            paths: ['src'],
            pathsProvided: true,
            scanPaths: [],
            scanPathsProvided: false,
            excludes: [],
            excludesProvided: false,
            backend: $backend,
            phpStanConfig: null,
            phpStanMemoryLimit: null,
            artifacts: $this->artifacts,
        );
    }

    private function source(string $class, string $method): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        final class {$class}
        {
            public function {$method}(): void
            {
            }
        }
        PHP;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
