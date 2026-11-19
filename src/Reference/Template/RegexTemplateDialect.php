<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template;

/**
 * A dialect whose code regions are the matches of a few delimiter patterns. Enough for engines with fixed,
 * non-nesting delimiters such as Twig and Blade.
 */
final readonly class RegexTemplateDialect implements TemplateDialect
{
    /**
     * @param list<string> $suffixes lower-case file name suffixes, e.g. `.twig`
     * @param list<string> $patterns PCRE patterns; each whole match is one code region
     */
    public function __construct(
        private string $engine,
        private array $suffixes,
        private array $patterns,
        private bool $attributeReachesGetter,
    ) {
    }

    /** `{{ }}`, `{% %}` and `{# #}`: Twig comments are kept because `{# @var \Fqcn name #}` is a type hint. */
    public static function twig(): self
    {
        return new self('twig', ['.twig'], ['/\{\{.*?\}\}/s', '/\{%.*?%\}/s', '/\{#.*?#\}/s'], true);
    }

    /** `{{ }}`, `{!! !!}` and directive arguments. Blade expressions are PHP: `->name` is a property, not a getter. */
    public static function blade(): self
    {
        return new self('blade', ['.blade.php'], ['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s', '/@\w+\([^\n]*\)/'], false);
    }

    public function engine(): string
    {
        return $this->engine;
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
        $regions = [];
        foreach ($this->patterns as $pattern) {
            if (preg_match_all($pattern, $source, $hits, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($hits[0] as [$text, $position]) {
                    $regions[] = [$position, $position + strlen($text)];
                }
            }
        }

        return $regions;
    }

    public function attributeReachesGetter(): bool
    {
        return $this->attributeReachesGetter;
    }
}
