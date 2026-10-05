<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Removal\BlankLineSeparation;

final class BlankLineSeparationTest extends TestCase
{
    public function testAMemberBetweenTwoBlankLinesLeavesExactlyOne(): void
    {
        self::assertSame("a\n\nb\n", $this->remove("a\n\nX\n\nb\n"));
    }

    public function testTheFirstMemberDoesNotLeaveABlankLineAfterTheOpeningBrace(): void
    {
        self::assertSame("{\n    b\n", $this->remove("{\nX\n\n    b\n"));
    }

    public function testTheLastMemberDoesNotLeaveABlankLineBeforeTheClosingBrace(): void
    {
        self::assertSame("    a\n}\n", $this->remove("    a\n\nX\n}\n"));
    }

    public function testAMemberWithoutBlankNeighboursIsLeftAlone(): void
    {
        self::assertSame("a\nb\n", $this->remove("a\nX\nb\n"));
    }

    public function testTheOnlyMemberLeavesTheBracesAdjacent(): void
    {
        self::assertSame("{\n}\n", $this->remove("{\nX\n}\n"));
    }

    private function remove(string $source): string
    {
        $start = strpos($source, 'X');
        self::assertIsInt($start);
        $end = strpos($source, "\n", $start);
        self::assertIsInt($end);
        $range = BlankLineSeparation::absorb($source, $start, $end);

        return substr($source, 0, $range['start']) . substr($source, $range['end'] + 1);
    }
}
