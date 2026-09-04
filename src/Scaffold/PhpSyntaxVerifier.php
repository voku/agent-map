<?php

declare(strict_types=1);

namespace voku\AgentMap\Scaffold;

use Throwable;
use voku\AgentMap\Plan\PlanEdit;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final readonly class PhpSyntaxVerifier
{
    /**
     * @param list<PlanEdit> $edits
     */
    public function verifyEdits(string $source, array $edits): ?string
    {
        // Sort edits in descending order of start offset to apply from back to front
        $sorted = $edits;
        usort($sorted, static fn (PlanEdit $a, PlanEdit $b): int => $b->startFilePos <=> $a->startFilePos);

        $modified = $source;
        foreach ($sorted as $edit) {
            $len = strlen($edit->expected);
            if ($edit->startFilePos > strlen($modified)) {
                return 'Edit start offset ' . $edit->startFilePos . ' is beyond source length ' . strlen($modified);
            }
            if ($len > 0) {
                $actual = substr($modified, $edit->startFilePos, $len);
                if ($actual !== $edit->expected) {
                    return sprintf(
                        'Edit precondition failed: expected %s, got %s',
                        var_export($edit->expected, true),
                        var_export($actual, true),
                    );
                }
            }
            $modified = substr($modified, 0, $edit->startFilePos) . $edit->replacement . substr($modified, $edit->startFilePos + $len);
        }

        return $this->verifySource($modified);
    }

    public function verifySource(string $source): ?string
    {
        try {
            $ast = PhpCodeParser::getAstFromString($source);
            if ($ast === []) {
                return 'Parsed AST is empty.';
            }

            return null;
        } catch (Throwable $e) {
            return 'PHP syntax error: ' . $e->getMessage();
        }
    }
}
