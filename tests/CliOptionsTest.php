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
        self::assertSame((getcwd() ?: '.') . '/map.json', $options->out);
        self::assertSame('512M', $options->phpStanMemoryLimit);
    }

    public function testArtifactPathsAreResolvedRelativeToRoot(): void
    {
        $cwd = getcwd() ?: '.';
        $options = CliOptions::parse([
            'build',
            '--root=target',
            '--out=.agent-loop/map/php-symbols.json',
            '--index=.agent-loop/map/php-symbols.json',
            '--database=.agent-loop/map/search.sqlite',
        ]);

        self::assertSame($cwd . '/target/.agent-loop/map/php-symbols.json', $options->out);
        self::assertSame($cwd . '/target/.agent-loop/map/php-symbols.json', $options->index);
        self::assertSame($cwd . '/target/.agent-loop/map/search.sqlite', $options->database);
    }

    public function testAbsoluteArtifactPathsRemainAbsolute(): void
    {
        $options = CliOptions::parse([
            'build',
            '--root=target',
            '--out=/tmp/agent-map/map.json',
            '--index=/tmp/agent-map/map.json',
            '--database=/tmp/agent-map/search.sqlite',
        ]);

        self::assertSame('/tmp/agent-map/map.json', $options->out);
        self::assertSame('/tmp/agent-map/map.json', $options->index);
        self::assertSame('/tmp/agent-map/search.sqlite', $options->database);
    }

    public function testBuildToonFormatUsesToonDefaultOutput(): void
    {
        $options = CliOptions::parse(['build', '--format=toon']);

        self::assertSame('toon', $options->format);
        self::assertSame((getcwd() ?: '.') . '/.agent-loop/map/php-symbols.toon', $options->out);
    }

    public function testBuildInfersToonFormatFromExplicitOutputExtension(): void
    {
        $options = CliOptions::parse(['build', '--out=map.toon']);

        self::assertSame('toon', $options->format);
        self::assertSame((getcwd() ?: '.') . '/map.toon', $options->out);
    }

    public function testParsesRepeatedExclude(): void
    {
        $options = CliOptions::parse(['build', '--exclude=~Generated~', '--exclude', '~fixtures~']);

        self::assertSame(['~Generated~', '~fixtures~'], $options->excludes);
    }

    public function testParsesDefaultValues(): void
    {
        $options = CliOptions::parse(['build']);
        $cwd = getcwd() ?: '.';

        self::assertSame(['.'], $options->paths);
        self::assertSame($cwd . '/.agent-loop/map/php-symbols.json', $options->out);
        self::assertSame($cwd . '/.agent-loop/map/php-symbols.json', $options->index);
        self::assertSame($cwd . '/.agent-loop/map/search.sqlite', $options->database);
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
}
