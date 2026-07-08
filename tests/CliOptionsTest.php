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
        $options = CliOptions::parse(['build', '--root=.', '--paths=src,tests', '--out=map.json', '--backend=mago']);

        self::assertSame('build', $options->command);
        self::assertSame(['src', 'tests'], $options->paths);
        self::assertSame('map.json', $options->out);
        self::assertSame('mago', $options->backend);
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
        self::assertSame('.agent-map/php-symbols.json', $options->out);
        self::assertSame('token', $options->backend);
        self::assertSame('text', $options->format);
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

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown format: xml');

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
}
