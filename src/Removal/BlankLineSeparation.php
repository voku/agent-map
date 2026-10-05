<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

/**
 * Widens a whole-line member deletion by the one blank separator line that would otherwise
 * be left behind as a double blank line or as a blank line before the closing brace.
 */
final class BlankLineSeparation
{
    /**
     * @return array{start: int, end: int}
     */
    public static function absorb(string $source, int $start, int $end): array
    {
        $following = self::lineAt($source, $end + 1);
        if ($following !== null && trim($following['text']) === '') {
            return ['start' => $start, 'end' => $following['end']];
        }

        $previousEnd = $start - 1;
        if ($previousEnd < 0 || $following === null || trim($following['text']) !== '}') {
            return ['start' => $start, 'end' => $end];
        }
        $previousStart = ($before = strrpos(substr($source, 0, $previousEnd), "\n")) === false ? 0 : $before + 1;
        if (trim(substr($source, $previousStart, $previousEnd - $previousStart)) === '') {
            return ['start' => $previousStart, 'end' => $end];
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @return array{text: string, end: int}|null */
    private static function lineAt(string $source, int $offset): ?array
    {
        if ($offset >= strlen($source)) {
            return null;
        }
        $newline = strpos($source, "\n", $offset);
        if ($newline === false) {
            return null;
        }

        return ['text' => substr($source, $offset, $newline - $offset), 'end' => $newline];
    }
}
