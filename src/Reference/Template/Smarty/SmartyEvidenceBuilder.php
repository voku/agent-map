<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template\Smarty;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentMap\Reference\Template\SmartyDialect;
use voku\AgentMap\Reference\Template\TemplateFact;

/**
 * Joins Smarty template facts with the PHP that renders and extends them, read-only, without loading Smarty.
 *
 * 1. Templates are found through the dialect, PHP files by extension (vendor, caches and compile dirs are skipped).
 * 2. Every render site seeds its template with the assigned variables and their static types.
 * 3. Variables flow on through `{include}` (Smarty includes inherit the caller's variables, and `u=$user` renames
 *    one) and from a child to the parent it `{extends}`, until nothing changes.
 * 4. Variable members, registered modifiers / function / block plugins and explicit static references become
 *    {@see SmartyTypedReference}s. What cannot be resolved (dynamic include or render, unknown template name,
 *    unresolvable callback, untyped variable) is listed as a gap, never silently dropped.
 *
 * Template names resolve against `$templateDirs` (repository-relative, Smarty's `setTemplateDir()`), or, when none are
 * given, against every template whose path ends with the name. `./` and `../` in includes resolve relative to the
 * including template.
 *
 * Experimental: see docs/experiments/template-engine-evidence.md.
 */
final readonly class SmartyEvidenceBuilder
{
    private const EXCLUDED_DIRS = ['.git', 'vendor', 'node_modules', '.agent-map', '.agent-loop', '.agent-edit', 'templates_c', 'cache', 'tmp'];
    private const MAX_FILE_BYTES = 1_000_000;

    /** @param list<string> $templateDirs */
    public function __construct(
        private SmartyDialect $dialect = new SmartyDialect(),
        private array $templateDirs = [],
    ) {
    }

    public function build(string $root): SmartyEvidenceReport
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot) || !is_dir($realRoot)) {
            return new SmartyEvidenceReport([], [], [], [], [], []);
        }

        [$templates, $phpFiles] = $this->files($realRoot);
        $analyzer = new SmartyTemplateAnalyzer($this->dialect);
        $extractor = new SmartyRenderSiteExtractor($this->dialect);

        /** @var array<string, list<TemplateFact>> $factsByTemplate */
        $factsByTemplate = [];
        $facts = [];
        foreach ($templates as $template) {
            $factsByTemplate[$template] = $analyzer->analyze($template, (string) file_get_contents($realRoot . '/' . $template));
            array_push($facts, ...$factsByTemplate[$template]);
        }
        $sites = [];
        $registrations = [];
        foreach ($phpFiles as $file) {
            $found = $extractor->extract($file, (string) file_get_contents($realRoot . '/' . $file));
            array_push($sites, ...$found['sites']);
            array_push($registrations, ...$found['registrations']);
        }

        $gaps = [];
        $contexts = $this->contexts($templates, $factsByTemplate, $sites, $gaps);
        $references = $this->references($factsByTemplate, $contexts, $registrations, $gaps);
        foreach ($registrations as $registration) {
            if ($registration->callback === null) {
                $gaps[] = ['kind' => 'unresolved_registration', 'path' => $registration->path, 'line' => $registration->line, 'detail' => $registration->kind . ' ' . ($registration->name ?? '')];
            }
        }
        usort($gaps, static fn (array $a, array $b): int => [$a['path'], $a['line'], $a['kind']] <=> [$b['path'], $b['line'], $b['kind']]);

        return new SmartyEvidenceReport($templates, $facts, $sites, $registrations, $references, $gaps);
    }

    /**
     * Variables (name => type => evidence) known to reach each template.
     *
     * @param list<string> $templates
     * @param array<string, list<TemplateFact>> $factsByTemplate
     * @param list<SmartyRenderSite> $sites
     * @param list<array{kind: string, path: string, line: int, detail: string}> $gaps
     * @return array<string, array<string, array<string, list<string>>>> template => variable => type ('' = unknown) => evidence
     */
    private function contexts(array $templates, array $factsByTemplate, array $sites, array &$gaps): array
    {
        $contexts = [];
        $queue = [];
        foreach ($sites as $site) {
            $evidence = $site->path . ':' . $site->line;
            if ($site->template === null) {
                $gaps[] = ['kind' => 'dynamic_render', 'path' => $site->path, 'line' => $site->line, 'detail' => $site->method . '() with a computed template name'];
                continue;
            }
            $targets = $this->resolve($site->template, null, $templates);
            if ($targets === []) {
                $gaps[] = ['kind' => 'unknown_template', 'path' => $site->path, 'line' => $site->line, 'detail' => $site->template];
                continue;
            }
            foreach ($targets as $target) {
                foreach ($site->variables as $name => $variable) {
                    $contexts[$target][$name][$variable['type'] ?? ''][] = $evidence;
                }
                $contexts[$target] ??= [];
                $queue[$target] = true;
            }
        }

        foreach ($factsByTemplate as $template => $facts) {
            foreach ($facts as $fact) {
                if (($fact->kind === TemplateFact::KIND_INCLUDE || $fact->kind === TemplateFact::KIND_EXTENDS) && $fact->target === null) {
                    $gaps[] = ['kind' => 'dynamic_' . $fact->kind, 'path' => $template, 'line' => $fact->line, 'detail' => $fact->text];
                } elseif (($fact->kind === TemplateFact::KIND_INCLUDE || $fact->kind === TemplateFact::KIND_EXTENDS) && $this->resolve((string) $fact->target, $template, $templates) === []) {
                    $gaps[] = ['kind' => 'unknown_template', 'path' => $template, 'line' => $fact->line, 'detail' => (string) $fact->target];
                }
            }
        }

        // Fixed point: contexts only grow, and they are bounded by the variables and types that exist.
        while ($queue !== []) {
            $template = (string) array_key_first($queue);
            unset($queue[$template]);
            foreach ($factsByTemplate[$template] ?? [] as $fact) {
                if (($fact->kind !== TemplateFact::KIND_INCLUDE && $fact->kind !== TemplateFact::KIND_EXTENDS) || $fact->target === null) {
                    continue;
                }
                $passed = $contexts[$template] ?? [];
                foreach ($fact->arguments as $parameter => $variable) {
                    if (isset($contexts[$template][$variable])) {
                        $passed[$parameter] = $contexts[$template][$variable];
                    }
                }
                foreach ($this->resolve($fact->target, $template, $templates) as $target) {
                    if ($this->merge($contexts, $target, $passed)) {
                        $queue[$target] = true;
                    }
                }
            }
        }

        return $contexts;
    }

    /**
     * @param array<string, array<string, array<string, list<string>>>> $contexts
     * @param array<string, array<string, list<string>>> $passed
     */
    private function merge(array &$contexts, string $target, array $passed): bool
    {
        $changed = !isset($contexts[$target]);
        $contexts[$target] ??= [];
        foreach ($passed as $name => $types) {
            foreach ($types as $type => $evidence) {
                $known = $contexts[$target][$name][$type] ?? [];
                $merged = array_values(array_unique([...$known, ...$evidence]));
                sort($merged, SORT_STRING);
                if ($merged !== $known) {
                    $contexts[$target][$name][$type] = $merged;
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /**
     * @param array<string, list<TemplateFact>> $factsByTemplate
     * @param array<string, array<string, array<string, list<string>>>> $contexts
     * @param list<SmartyPluginRegistration> $registrations
     * @param list<array{kind: string, path: string, line: int, detail: string}> $gaps
     * @return list<SmartyTypedReference>
     */
    private function references(array $factsByTemplate, array $contexts, array $registrations, array &$gaps): array
    {
        /** @var array<string, array<string, list<SmartyPluginRegistration>>> $plugins kind group => name => registrations */
        $plugins = [];
        /** @var array<string, array{string, string}> $aliases alias => [class, evidence] */
        $aliases = [];
        foreach ($registrations as $registration) {
            if ($registration->kind === SmartyPluginRegistration::KIND_PLUGIN && $registration->name !== null && $registration->callback !== null) {
                $group = in_array($registration->type, ['modifier', 'modifiercompiler'], true) ? 'modifier' : 'tag';
                $plugins[$group][strtolower($registration->name)][] = $registration;
            } elseif ($registration->kind === SmartyPluginRegistration::KIND_CLASS && $registration->name !== null && $registration->callback !== null) {
                $aliases[strtolower($registration->name)] = [$registration->callback, $registration->path . ':' . $registration->line];
            }
        }

        $references = [];
        foreach ($factsByTemplate as $template => $facts) {
            foreach ($facts as $fact) {
                if ($fact->kind === TemplateFact::KIND_VARIABLE_MEMBER) {
                    $types = $contexts[$template][(string) $fact->target] ?? [];
                    $known = array_filter(array_keys($types), static fn (string|int $type): bool => $type !== '');
                    if ($known === []) {
                        $gaps[] = ['kind' => 'untyped_variable', 'path' => $template, 'line' => $fact->line, 'detail' => $fact->text];
                        continue;
                    }
                    $ambiguous = count($types) > 1;
                    foreach ($known as $type) {
                        $references[] = $this->reference(SmartyTypedReference::CONFIDENCE_RENDER_CONTEXT, $fact, (string) $type, $fact->member, $ambiguous, $types[$type]);
                    }
                } elseif ($fact->kind === TemplateFact::KIND_MODIFIER || $fact->kind === TemplateFact::KIND_TAG) {
                    $group = $fact->kind === TemplateFact::KIND_MODIFIER ? 'modifier' : 'tag';
                    foreach ($plugins[$group][strtolower((string) $fact->target)] ?? [] as $registration) {
                        [$class, $member] = $this->split((string) $registration->callback);
                        $references[] = $this->reference(SmartyTypedReference::CONFIDENCE_REGISTERED_PLUGIN, $fact, $class, $member, count($plugins[$group][strtolower((string) $fact->target)]) > 1, [$registration->path . ':' . $registration->line]);
                    }
                } elseif ($fact->kind === TemplateFact::KIND_STATIC_REFERENCE) {
                    [$class, $evidence] = $aliases[strtolower((string) $fact->target)] ?? [(string) $fact->target, null];
                    $references[] = $this->reference(SmartyTypedReference::CONFIDENCE_STATIC, $fact, $class, $fact->member, false, $evidence === null ? [] : [$evidence]);
                }
            }
        }

        return $references;
    }

    /** @param list<string> $evidence */
    private function reference(string $confidence, TemplateFact $fact, string $class, ?string $member, bool $ambiguous, array $evidence): SmartyTypedReference
    {
        return new SmartyTypedReference($confidence, $fact->path, $fact->line, $fact->startFilePos, $fact->endFilePos, $fact->text, $class, $member, $ambiguous, $evidence);
    }

    /** @return array{string, string|null} */
    private function split(string $callback): array
    {
        $parts = explode('::', $callback, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * @param list<string> $templates
     * @return list<string>
     */
    private function resolve(string $name, ?string $from, array $templates): array
    {
        $name = str_replace('\\', '/', $name);
        if ($from !== null && (str_starts_with($name, './') || str_starts_with($name, '../'))) {
            $candidate = $this->normalize(dirname($from) . '/' . $name);

            return in_array($candidate, $templates, true) ? [$candidate] : [];
        }
        $name = ltrim($name, '/');
        if ($this->templateDirs !== []) {
            $found = [];
            foreach ($this->templateDirs as $directory) {
                $candidate = $this->normalize(trim($directory, '/') . '/' . $name);
                if (in_array($candidate, $templates, true)) {
                    $found[] = $candidate; // first directory wins in Smarty; all are listed so overlap stays visible
                }
            }

            return $found;
        }

        return array_values(array_filter($templates, static fn (string $template): bool => $template === $name || str_ends_with($template, '/' . $name)));
    }

    private function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /** @return array{list<string>, list<string>} templates and PHP files, repository-relative and sorted */
    private function files(string $root): array
    {
        $templates = [];
        $php = [];
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
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if ($this->dialect->handles($relative)) {
                $templates[] = $relative;
            } elseif (str_ends_with(strtolower($relative), '.php')) {
                $php[] = $relative;
            }
        }
        sort($templates, SORT_STRING);
        sort($php, SORT_STRING);

        return [$templates, $php];
    }
}
