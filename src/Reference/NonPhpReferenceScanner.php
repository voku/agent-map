<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds mentions of a PHP symbol in Markdown documents and Twig / Smarty / Blade templates.
 *
 * Text matching is not semantics: a template variable has no static type and prose is ambiguous.
 * Every hit therefore carries a confidence and the report only lists residue for a later, separately
 * governed cleanup; it never changes whether the PHP edit is provable.
 */
final class NonPhpReferenceScanner
{
    private const EXCLUDED_DIRS = [
        '.git', 'vendor', 'node_modules', '.agent-map', '.agent-loop', '.agent-edit',
        'templates_c', 'cache', 'tmp',
    ];

    private const HISTORICAL_FILES = ['changelog', 'upgrading', 'changes', 'history', 'news', 'releases', 'release_notes'];
    private const MAX_FILE_BYTES = 1_000_000;

    public function __construct(private readonly int $maxReferences = 200)
    {
    }

    public function scan(string $root, ReferenceTarget ...$targets): NonPhpReferenceReport
    {
        $realRoot = realpath($root);
        if ($targets === [] || !is_string($realRoot) || !is_dir($realRoot)) {
            return new NonPhpReferenceReport([], 0, 0);
        }

        $references = [];
        $total = 0;
        $scanned = 0;
        foreach ($this->files($realRoot) as $relative => $engine) {
            $source = @file_get_contents($realRoot . '/' . $relative);
            if (!is_string($source) || $source === '') {
                continue;
            }
            ++$scanned;
            $historical = $this->isHistorical($relative);
            $found = [];
            foreach ($targets as $target) {
                $hits = $engine === 'markdown'
                    ? $this->markdown($source, $relative, $target, $historical)
                    : $this->template($source, $relative, $target, $historical);
                foreach ($hits as $hit) {
                    $found[$hit->startFilePos . ':' . $hit->endFilePos] ??= $hit;
                }
            }
            ksort($found, SORT_NATURAL);
            foreach ($found as $reference) {
                ++$total;
                if (count($references) < $this->maxReferences) {
                    $references[] = $reference;
                }
            }
        }

        return new NonPhpReferenceReport($references, $total, $scanned);
    }

    /** @return array<string, string> relative path => engine (markdown|template), sorted by path */
    private function files(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), self::EXCLUDED_DIRS, true)),
            ),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getSize() > self::MAX_FILE_BYTES) {
                continue;
            }
            $name = strtolower($file->getFilename());
            $engine = match (true) {
                str_ends_with($name, '.md'), str_ends_with($name, '.markdown') => 'markdown',
                str_ends_with($name, '.twig'), str_ends_with($name, '.tpl'), str_ends_with($name, '.blade.php') => 'template',
                default => null,
            };
            if ($engine === null) {
                continue;
            }
            $files[ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/')] = $engine;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function isHistorical(string $relative): bool
    {
        $segments = explode('/', strtolower($relative));
        $base = pathinfo(array_pop($segments), PATHINFO_FILENAME);
        if (in_array($base, self::HISTORICAL_FILES, true)) {
            return true;
        }

        return array_intersect($segments, ['changelog', 'releases']) !== [];
    }

    /** @return list<NonPhpReference> */
    private function markdown(string $source, string $path, ReferenceTarget $target, bool $historical): array
    {
        $found = [];
        $offset = 0;
        $fenced = false;
        foreach (preg_split('/(?<=\n)/', $source) ?: [] as $index => $line) {
            $lineStart = $offset;
            $offset += strlen($line);
            if (preg_match('/^\s{0,3}(?:`{3,}|~{3,})/', $line) === 1) {
                $fenced = !$fenced;
                continue;
            }
            $codeRegions = $fenced ? [[0, strlen($line)]] : $this->inlineCode($line);
            foreach ($this->matches($line, $target, true) as [$position, $text, $confidence, $codeOnly]) {
                if ($codeOnly && !$this->within($position, $codeRegions)) {
                    continue;
                }
                $found[] = new NonPhpReference(
                    NonPhpReference::KIND_MARKDOWN,
                    $confidence,
                    $path,
                    $index + 1,
                    $lineStart + $position,
                    $lineStart + $position + strlen($text) - 1,
                    $text,
                    $historical,
                );
            }
        }

        return $found;
    }

    /** @return list<NonPhpReference> */
    private function template(string $source, string $path, ReferenceTarget $target, bool $historical): array
    {
        $regions = [];
        $patterns = str_ends_with(strtolower($path), '.tpl')
            ? ['/\{(?!\s)[^{}]*\}/s']
            : ['/\{\{.*?\}\}/s', '/\{%.*?%\}/s', '/\{#.*?#\}/s', '/\{!!.*?!!\}/s', '/@\w+\([^\n]*\)/'];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $hits, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($hits[0] as [$text, $position]) {
                    $regions[] = [$position, $position + strlen($text)];
                }
            }
        }
        if ($regions === []) {
            return [];
        }

        $found = [];
        foreach ($this->matches($source, $target, false) as [$position, $text, $confidence]) {
            if (!$this->within($position, $regions)) {
                continue;
            }
            $found[] = new NonPhpReference(
                NonPhpReference::KIND_TEMPLATE,
                $confidence,
                $path,
                substr_count($source, "\n", 0, $position) + 1,
                $position,
                $position + strlen($text) - 1,
                $text,
                $historical,
            );
        }

        return $found;
    }

    /**
     * @return list<array{int, string, string, bool}> position, matched text, confidence, only-inside-code
     */
    private function matches(string $text, ReferenceTarget $target, bool $markdown): array
    {
        $segments = array_map(static fn (string $segment): string => preg_quote($segment, '/'), explode('\\', $target->classFqn));
        $fqcn = '\\\\?' . implode('\\\\{1,2}', $segments);
        $short = preg_quote($target->shortName(), '/');
        $hits = [];

        if ($target->member === null) {
            $this->collect($hits, '/(?<![\w\\\\])' . $fqcn . '(?!\w)/', $text, NonPhpReference::CONFIDENCE_EXACT_FQCN, false);
            if ($markdown) {
                $this->collect($hits, '/(?<![\w\\\\$>:])' . $short . '(?!\w)/', $text, NonPhpReference::CONFIDENCE_CODE_NAME, true);
            }

            return $this->withoutOverlaps($hits);
        }

        $member = preg_quote($target->member, '/');
        $this->collect($hits, '/(?<![\w\\\\])' . $fqcn . '\s*(?:::|->)\s*' . $member . '(?!\w)/', $text, NonPhpReference::CONFIDENCE_EXACT_FQCN, false);
        $this->collect($hits, '/(?<![\w\\\\$])' . $short . '\s*(?:::|->)\s*' . $member . '(?!\w)/', $text, NonPhpReference::CONFIDENCE_CLASS_MEMBER, false);
        $this->collect($hits, '/(?:->|::|\.)' . $member . '(?!\w)/', $text, NonPhpReference::CONFIDENCE_MEMBER_NAME_ONLY, $markdown);
        if ($markdown) {
            $this->collect($hits, '/(?<![\w$>:.])' . $member . '\s*\(/', $text, NonPhpReference::CONFIDENCE_MEMBER_NAME_ONLY, true);
        } elseif (preg_match('/^(?:get|is|has)([A-Z]\w*)$/', $target->member, $getter) === 1) {
            // Twig and Smarty reach getName()/isName()/hasName() through the attribute `.name`.
            $this->collect($hits, '/(?:->|\.)' . preg_quote(lcfirst($getter[1]), '/') . '(?!\w)/', $text, NonPhpReference::CONFIDENCE_MEMBER_NAME_ONLY, false);
        }

        return $this->withoutOverlaps($hits);
    }

    /** @param list<array{int, string, string, bool}> $hits */
    private function collect(array &$hits, string $pattern, string $text, string $confidence, bool $codeOnly): void
    {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$matched, $position]) {
                $hits[] = [$position, $matched, $confidence, $codeOnly];
            }
        }
    }

    /**
     * The earlier, more specific pattern wins when two patterns hit overlapping text.
     *
     * @param list<array{int, string, string, bool}> $hits
     * @return list<array{int, string, string, bool}>
     */
    private function withoutOverlaps(array $hits): array
    {
        $kept = [];
        foreach ($hits as $hit) {
            foreach ($kept as $other) {
                if ($hit[0] < $other[0] + strlen($other[1]) && $other[0] < $hit[0] + strlen($hit[1])) {
                    continue 2;
                }
            }
            $kept[] = $hit;
        }
        usort($kept, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $kept;
    }

    /** @return list<array{int, int}> */
    private function inlineCode(string $line): array
    {
        $regions = [];
        if (preg_match_all('/(`+)(.+?)\1/', $line, $hits, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($hits[2] as [$content, $position]) {
                $regions[] = [$position, $position + strlen($content)];
            }
        }

        return $regions;
    }

    /** @param list<array{int, int}> $regions */
    private function within(int $position, array $regions): bool
    {
        foreach ($regions as [$start, $end]) {
            if ($position >= $start && $position < $end) {
                return true;
            }
        }

        return false;
    }
}
