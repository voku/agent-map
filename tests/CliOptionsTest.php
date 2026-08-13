<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\CliOptions;

final class CliOptionsTest extends TestCase
{
    public function testParsesBuild(): void
    {
        $options = CliOptions::parse(['build', '--root=.', '--paths=src,tests', '--out=map.json', '--phpstan-memory-limit=512m']);

        self::assertSame('build', $options->command);
        self::assertSame(['src', 'tests'], $options->paths);
        self::assertSame($this->cwd() . DIRECTORY_SEPARATOR . 'map.json', $options->out);
        self::assertSame('512M', $options->phpStanMemoryLimit);
    }

    public function testArtifactPathsAreResolvedAgainstExplicitRoot(): void
    {
        $root = $this->cwd() . DIRECTORY_SEPARATOR . 'target';

        $options = CliOptions::parse(['build', '--root=target']);

        self::assertSame($root, $options->root);
        self::assertSame($root . DIRECTORY_SEPARATOR . '.agent-loop/map/php-symbols.json', $options->out);
        self::assertSame($root . DIRECTORY_SEPARATOR . '.agent-loop/map/php-symbols.json', $options->index);
        self::assertSame($root . DIRECTORY_SEPARATOR . '.agent-loop/map/search.sqlite', $options->database);
    }

    public function testAbsoluteArtifactPathsRemainAuthoritative(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent-map-root';
        $out = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent-map-out.json';
        $index = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent-map-index.json';
        $database = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent-map-search.sqlite';

        $options = CliOptions::parse([
            'build',
            '--root=' . $root,
            '--out=' . $out,
            '--index=' . $index,
            '--database=' . $database,
        ]);

        self::assertSame($root, $options->root);
        self::assertSame($out, $options->out);
        self::assertSame($index, $options->index);
        self::assertSame($database, $options->database);
    }

    public function testBuildToonFormatUsesToonDefaultOutput(): void
    {
        $options = CliOptions::parse(['build', '--format=toon']);

        self::assertSame('toon', $options->format);
        self::assertSame($this->cwd() . DIRECTORY_SEPARATOR . '.agent-loop/map/php-symbols.toon', $options->out);
    }

    public function testBuildInfersToonFormatFromExplicitOutputExtension(): void
    {
        $options = CliOptions::parse(['build', '--out=map.toon']);

        self::assertSame('toon', $options->format);
        self::assertSame($this->cwd() . DIRECTORY_SEPARATOR . 'map.toon', $options->out);
    }

    public function testParsesRepeatedExclude(): void
    {
        $options = CliOptions::parse(['build', '--exclude=~Generated~', '--exclude', '~fixtures~']);

        self::assertSame(['~Generated~', '~fixtures~'], $options->excludes);
    }

    public function testParsesDefaultValues(): void
    {
        $options = CliOptions::parse(['build']);

        self::assertSame(['.'], $options->paths);
        self::assertSame($this->cwd() . DIRECTORY_SEPARATOR . '.agent-loop/map/php-symbols.json', $options->out);
        self::assertSame($this->cwd() . DIRECTORY_SEPARATOR . '.agent-loop/map/php-symbols.json', $options->index);
        self::assertSame($this->cwd() . DIRECTORY_SEPARATOR . '.agent-loop/map/search.sqlite', $options->database);
        self::assertSame('json', $options->format);
        self::assertSame(20, $options->limit);
        self::assertSame(10, $options->symbolLimit);
        self::assertSame(10, $options->methodLimit);
        self::assertSame('main', $options->base);
    }

    public function testParsesFormatLimitAndBase(): void
    {
        $options = CliOptions::parse(['changed', '--format=toon', '--limit=5', '--symbol-limit=2', '--method-limit=3', '--base=develop']);

        self::assertSame('changed', $options->command);
        self::assertSame('toon', $options->format);
        self::assertSame(5, $options->limit);
        self::assertSame(2, $options->symbolLimit);
        self::assertSame(3, $options->methodLimit);
        self::assertSame('develop', $options->base);
    }

    public function testParsesContextOptions(): void
    {
        $options = CliOptions::parse(['context', 'Foo::bar', '--context-budget=1234', '--max-callers=3', '--max-callees=4', '--max-tests=5', '--max-files=6']);

        self::assertSame('Foo::bar', $options->argument);
        self::assertSame(1234, $options->contextBudget);
        self::assertSame(3, $options->maxCallers);
        self::assertSame(4, $options->maxCallees);
        self::assertSame(5, $options->maxTests);
        self::assertSame(6, $options->maxFiles);
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown format for query: xml');

        CliOptions::parse(['query', 'Foo', '--format=xml']);
    }

    public function testRejectsMissingCommand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing command');

        CliOptions::parse([]);
    }

    public function testHandlesUnknownCommandCleanly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown command: nope');

        CliOptions::parse(['nope']);
    }

    public function testRejectsInvalidPhpStanMemoryLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid phpstan-memory-limit');

        CliOptions::parse(['build', '--phpstan-memory-limit=-1']);
    }

    private function cwd(): string
    {
        $cwd = getcwd();
        self::assertIsString($cwd);

        return rtrim($cwd, '/\\');
    }
}
