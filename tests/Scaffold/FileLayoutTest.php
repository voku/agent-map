<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Scaffold\FileLayout;

final class FileLayoutTest extends TestCase
{
    public function testDetectsTheDominantLineEnding(): void
    {
        self::assertSame("\r\n", FileLayout::eol("<?php\r\nclass A {}\r\n"));
        self::assertSame("\n", FileLayout::eol("<?php\nclass A {}\n"));
        self::assertSame("\n", FileLayout::eol('<?php class A {}'), 'no line break: LF');
        self::assertSame("\r\n", FileLayout::eol("a\r\nb\r\nc\n"));
        self::assertSame("\n", FileLayout::eol("a\nb\nc\r\n"));
    }

    public function testDetectsTheIndentationUnit(): void
    {
        self::assertSame("\t", FileLayout::indentUnit("<?php\nclass A\n{\n\tpublic \$x;\n}\n"));
        self::assertSame('    ', FileLayout::indentUnit("<?php\nclass A\n{\n    public \$x;\n}\n"));
        self::assertSame('  ', FileLayout::indentUnit("<?php\nclass A\n{\n  public \$x;\n}\n"));
        self::assertSame('    ', FileLayout::indentUnit('<?php class A {}'), 'nothing indented: four spaces');
    }

    public function testWithEolNormalizesEveryBreak(): void
    {
        self::assertSame("a\r\nb\r\nc", FileLayout::withEol("a\nb\r\nc", "\r\n"));
        self::assertSame("a\nb\nc", FileLayout::withEol("a\r\nb\rc", "\n"));
        self::assertSame('', FileLayout::withEol('', "\r\n"));
    }
}
