<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template\Smarty;

use voku\AgentMap\Reference\Template\SmartyDialect;
use voku\AgentMap\Reference\Template\TemplateFact;

/**
 * Extracts {@see TemplateFact}s from one Smarty 5 template without loading Smarty.
 *
 * Tag boundaries come from {@see SmartyDialect} (comments and `{literal}` are skipped). Inside a tag it reads:
 * the tag name (`{money ...}`), `{include}` / `{extends}` targets (static string or dynamic), named include arguments that pass a variable on
 * (`u=$user`), `$var->member` accesses (the first member after the variable; deeper chains need return types), modifier
 * uses (`|price`), and static references (`Foo::bar()`). `$smarty.*` is Smarty's own namespace and is ignored; `.key`
 * is array access and is not a member.
 *
 * Experimental: see docs/experiments/template-engine-evidence.md.
 */
final readonly class SmartyTemplateAnalyzer
{
    private const IGNORED_INCLUDE_ARGUMENTS = ['file', 'assign', 'cache_lifetime', 'cache_id', 'compile_id', 'nocache', 'caching', 'scope', 'inline'];

    public function __construct(private SmartyDialect $dialect = new SmartyDialect())
    {
    }

    public function dialect(): SmartyDialect
    {
        return $this->dialect;
    }

    /** @return list<TemplateFact> in source order */
    public function analyze(string $path, string $source): array
    {
        $left = strlen($this->dialect->leftDelimiter());
        $right = strlen($this->dialect->rightDelimiter());
        $facts = [];
        foreach ($this->dialect->codeRegions($source) as [$start, $end]) {
            $innerStart = $start + $left;
            $inner = substr($source, $innerStart, max(0, $end - $right - $innerStart));
            array_push($facts, ...$this->tag($path, $source, $inner, $innerStart));
        }
        usort($facts, static fn (TemplateFact $a, TemplateFact $b): int => [$a->startFilePos, $a->kind] <=> [$b->startFilePos, $b->kind]);

        return $facts;
    }

    /** @return list<TemplateFact> */
    private function tag(string $path, string $source, string $inner, int $offset): array
    {
        $facts = [];
        if (preg_match('/\A(\s*)([A-Za-z_]\w*)(?![\w\\:(])/', $inner, $tag) === 1) {
            // `{money amount=$x}` / `{cache}...{/cache}`: joined later with registered function and block plugins.
            $facts[] = $this->fact(TemplateFact::KIND_TAG, $path, $source, $offset + strlen($tag[1]), strlen($tag[2]), $tag[2]);
        }
        if (preg_match('/\A\s*(include|extends)\b/i', $inner, $name) === 1) {
            $facts[] = $this->templateEdge($path, $source, $inner, $offset, strtolower($name[1]));
        }

        // Variable members: single-quoted strings are literal text; double-quoted ones interpolate variables.
        $variables = $this->mask($inner, false);
        if (preg_match_all('/\$(?!smarty\b)(\w+)\s*->\s*(\w+)(\s*\()?/', $variables, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($hits as $hit) {
                $facts[] = $this->fact(TemplateFact::KIND_VARIABLE_MEMBER, $path, $source, $offset + $hit[0][1], strlen(rtrim($hit[0][0], " \t\r\n(")), $hit[1][0], $hit[2][0], isset($hit[3]));
            }
        }

        $code = $this->mask($inner, true);
        if (preg_match_all('/(?<!\|)\|(?!\|)\s*@?(\w+)/', $code, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($hits as $hit) {
                $facts[] = $this->fact(TemplateFact::KIND_MODIFIER, $path, $source, $offset + $hit[1][1], strlen($hit[1][0]), $hit[1][0]);
            }
        }
        if (preg_match_all('/(?<![\w\\\\$>:.])(\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*)\s*::\s*(\$?\w+)(\s*\()?/', $code, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($hits as $hit) {
                $facts[] = $this->fact(TemplateFact::KIND_STATIC_REFERENCE, $path, $source, $offset + $hit[0][1], strlen(rtrim($hit[0][0], " \t\r\n(")), ltrim($hit[1][0], '\\'), $hit[2][0], isset($hit[3]));
            }
        }

        return $facts;
    }

    private function templateEdge(string $path, string $source, string $inner, int $offset, string $kind): TemplateFact
    {
        $value = null;
        if (preg_match('/\bfile\s*=\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s}]+)/', $inner, $file) === 1) {
            $value = $file[1];
        } elseif (preg_match('/\A\s*' . $kind . '\s+("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/i', $inner, $positional) === 1) {
            $value = $positional[1];
        }

        $target = null;
        if ($value !== null && strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            $literal = substr($value, 1, -1);
            if (!str_contains($literal, '$') && !str_contains($literal, $this->dialect->leftDelimiter()) && !str_contains($literal, '`')) {
                $target = preg_replace('/\Afile:/', '', $literal);
            }
        }

        $arguments = [];
        if ($kind === 'include' && preg_match_all('/\b(\w+)\s*=\s*\$(?!smarty\b)(\w+)(?![\w.\[]|\s*->)/', $this->mask($inner, true), $named, PREG_SET_ORDER) > 0) {
            foreach ($named as $argument) {
                if (!in_array(strtolower($argument[1]), self::IGNORED_INCLUDE_ARGUMENTS, true)) {
                    $arguments[$argument[1]] = $argument[2];
                }
            }
        }

        $leading = strlen($inner) - strlen(ltrim($inner));
        $text = trim($inner);

        return new TemplateFact(
            $kind === 'include' ? TemplateFact::KIND_INCLUDE : TemplateFact::KIND_EXTENDS,
            $path,
            $this->line($source, $offset + $leading),
            $offset + $leading,
            $offset + $leading + strlen($text) - 1,
            $text,
            $target,
            dynamic: $target === null,
            arguments: $arguments,
        );
    }

    private function fact(string $kind, string $path, string $source, int $position, int $length, string $target, ?string $member = null, bool $call = false): TemplateFact
    {
        return new TemplateFact($kind, $path, $this->line($source, $position), $position, $position + $length - 1, substr($source, $position, $length), $target, $member, $call);
    }

    /** Replaces the content of quoted strings with spaces (offsets are kept); double quotes only when `$double`. */
    private function mask(string $text, bool $double): string
    {
        $quote = null;
        $length = strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $char = $text[$index];
            if ($quote === null) {
                if ($char === "'" || $char === '"') {
                    $quote = $char;
                }
                continue;
            }
            if ($char === $quote) {
                $quote = null;
                continue;
            }
            if ($quote === "'" || $double) {
                $text[$index] = ' ';
                if ($char === '\\' && $index + 1 < $length) {
                    $text[++$index] = ' ';
                }
            } elseif ($char === '\\') {
                ++$index;
            }
        }

        return $text;
    }

    private function line(string $source, int $position): int
    {
        return substr_count($source, "\n", 0, $position) + 1;
    }
}
