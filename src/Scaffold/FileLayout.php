<?php

declare(strict_types=1);

namespace voku\AgentMap\Scaffold;

/**
 * The line ending and indentation a planner's generated text must follow so an inserted declaration or import fits
 * the file it lands in instead of leaving a CRLF file with bare LF breaks or a tab-indented file with spaces.
 */
final readonly class FileLayout
{
    /** The file's dominant line ending; a file without line breaks gets "\n". */
    public static function eol(string $source): string
    {
        $crlf = substr_count($source, "\r\n");
        $lf = substr_count($source, "\n") - $crlf;

        return $crlf > $lf ? "\r\n" : "\n";
    }

    /** One indentation level: the first indented line's leading whitespace (a tab, or its spaces; four by default). */
    public static function indentUnit(string $source): string
    {
        if (preg_match('/^(\t+|[ ]+)\S/m', $source, $matches) === 1) {
            return $matches[1][0] === "\t" ? "\t" : (strlen($matches[1]) % 4 === 0 ? '    ' : $matches[1]);
        }

        return '    ';
    }

    /** Rewrites every line break of generated or copied `$text` as `$eol`. */
    public static function withEol(string $text, string $eol): string
    {
        return str_replace("\n", $eol, str_replace(["\r\n", "\r"], "\n", $text));
    }
}
