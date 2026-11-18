<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template;

use InvalidArgumentException;

/**
 * Smarty tag boundaries as Smarty 5's lexer draws them, without loading Smarty.
 *
 * - `{* ... *}` is a comment and never code;
 * - `{literal} ... {/literal}` is text (typically inline JavaScript or CSS);
 * - with auto-literal (Smarty's default) a left delimiter followed by whitespace is text, so `{ a: 1 }` is not a tag;
 * - quotes and nested tags (`{$a[{$b}]}`, `{"x{$y}"}`) do not end a tag early;
 * - `.name` is array access and `->name` a property in Smarty, so attribute access never reaches a getter.
 *
 * Delimiters and file suffixes are configurable because projects change them (`setLeftDelimiter('<{')`).
 */
final readonly class SmartyDialect implements TemplateDialect
{
    /** @param list<string> $suffixes lower-case file name suffixes */
    public function __construct(
        private string $leftDelimiter = '{',
        private string $rightDelimiter = '}',
        private array $suffixes = ['.tpl'],
        private bool $autoLiteral = true,
    ) {
        if ($leftDelimiter === '' || $rightDelimiter === '') {
            throw new InvalidArgumentException('Smarty delimiters must not be empty.');
        }
    }

    public function engine(): string
    {
        return 'smarty';
    }

    public function handles(string $relativePath): bool
    {
        $name = strtolower($relativePath);
        foreach ($this->suffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public function codeRegions(string $source): array
    {
        $left = $this->leftDelimiter;
        $right = $this->rightDelimiter;
        $regions = [];
        $length = strlen($source);
        $offset = 0;
        while (($start = strpos($source, $left, $offset)) !== false) {
            $inner = $start + strlen($left);
            if (substr($source, $inner, 1) === '*') {
                $close = strpos($source, '*' . $right, $inner + 1);
                if ($close === false) {
                    break; // unterminated comment: the rest of the file is comment
                }
                $offset = $close + 1 + strlen($right);
                continue;
            }
            if ($this->autoLiteral && $inner < $length && ctype_space($source[$inner])) {
                $offset = $inner;
                continue;
            }
            if ($this->isTag($source, $inner, 'literal')) {
                $close = stripos($source, $left . '/literal' . $right, $inner);
                if ($close === false) {
                    break;
                }
                $offset = $close + strlen($left . '/literal' . $right);
                continue;
            }
            $end = $this->tagEnd($source, $inner);
            if ($end === null) {
                break; // unterminated tag: no region can be claimed
            }
            $regions[] = [$start, $end];
            $offset = $end;
        }

        return $regions;
    }

    public function attributeReachesGetter(): bool
    {
        return false;
    }

    private function isTag(string $source, int $inner, string $name): bool
    {
        $candidate = substr($source, $inner, strlen($name) + strlen($this->rightDelimiter));

        return strcasecmp($candidate, $name . $this->rightDelimiter) === 0;
    }

    /**
     * Byte offset just after the right delimiter that closes the tag whose body starts at `$from`. A stack of
     * contexts (tag, double-quoted string, single-quoted string) lets a nested tag inside a string or inside the tag
     * close without closing the outer tag.
     */
    private function tagEnd(string $source, int $from): ?int
    {
        $left = $this->leftDelimiter;
        $right = $this->rightDelimiter;
        $length = strlen($source);
        $stack = ['tag'];
        for ($index = $from; $index < $length; ++$index) {
            $char = $source[$index];
            $context = $stack[array_key_last($stack)];
            if ($context !== 'tag') {
                if ($char === '\\') {
                    ++$index;
                } elseif ($char === ($context === 'double' ? '"' : "'")) {
                    array_pop($stack);
                } elseif ($context === 'double' && substr_compare($source, $left, $index, strlen($left)) === 0) {
                    $stack[] = 'tag'; // `{"Hello {$name}"}`
                    $index += strlen($left) - 1;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $stack[] = $char === '"' ? 'double' : 'single';
            } elseif (substr_compare($source, $right, $index, strlen($right)) === 0) {
                array_pop($stack);
                $index += strlen($right) - 1;
                if ($stack === []) {
                    return $index + 1;
                }
            } elseif (substr_compare($source, $left, $index, strlen($left)) === 0) {
                $stack[] = 'tag'; // `{$a[{$b}]}`
                $index += strlen($left) - 1;
            }
        }

        return null;
    }
}
