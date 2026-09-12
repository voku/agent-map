<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final class TemplateParser
{
    public function parse(string $relativePath, string $content): TemplateInfo
    {
        $engine = $this->detectEngine($relativePath);
        $name = $this->extractTemplateName($relativePath);
        $includes = $this->extractIncludes($content, $engine);
        $forms = $this->extractForms($content);
        $inputs = $this->extractInputs($content);
        $xajax = $this->extractXajax($content);

        return new TemplateInfo(
            path: $relativePath,
            name: $name,
            engine: $engine,
            includes: $includes,
            forms: $forms,
            inputs: $inputs,
            xajax: $xajax,
        );
    }

    private function detectEngine(string $path): string
    {
        if (str_ends_with($path, '.blade.php')) {
            return 'blade';
        }
        if (str_ends_with($path, '.twig') || str_ends_with($path, '.html.twig')) {
            return 'twig';
        }

        return 'smarty';
    }

    private function extractTemplateName(string $relativePath): string
    {
        $clean = str_replace('\\', '/', $relativePath);

        // Strip common prefix directories like smarty/templates/, templates/, resources/views/
        foreach (['smarty/templates/', 'templates/', 'resources/views/', 'views/'] as $prefix) {
            if (str_starts_with($clean, $prefix)) {
                return substr($clean, strlen($prefix));
            }
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private function extractIncludes(string $content, string $engine): array
    {
        $includes = [];

        if ($engine === 'smarty') {
            if (preg_match_all('~\{include\s+(?:file=)?[\'"]([^\'"]+)[\'"]~i', $content, $matches)) {
                foreach ($matches[1] as $inc) {
                    if (!str_starts_with($inc, '$')) {
                        $includes[] = $inc;
                    }
                }
            }
        } elseif ($engine === 'twig') {
            if (preg_match_all('~\{%\s*include\s+[\'"]([^\'"]+)[\'"]~i', $content, $matches)) {
                foreach ($matches[1] as $inc) {
                    $includes[] = $inc;
                }
            }
        } elseif ($engine === 'blade') {
            if (preg_match_all('~@include\(\s*[\'"]([^\'"]+)[\'"]~i', $content, $matches)) {
                foreach ($matches[1] as $inc) {
                    $includes[] = $inc;
                }
            }
        }

        return array_values(array_unique($includes));
    }

    /**
     * @return list<array{action: ?string, method: ?string, params: array<string, string>}>
     */
    private function extractForms(string $content): array
    {
        $forms = [];

        if (preg_match_all('~<form\b(?P<attrs>[^>]*)>~i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs = $match['attrs'];
                $action = null;
                $method = 'get';
                $params = [];

                if (preg_match('~\baction=[\'"]([^\'"]*)[\'"]~i', $attrs, $actMatch)) {
                    $action = $actMatch[1];
                    $query = parse_url($action, PHP_URL_QUERY);
                    if (is_string($query)) {
                        parse_str($query, $parsedQuery);
                        foreach ($parsedQuery as $k => $v) {
                            if (is_string($k) && is_scalar($v)) {
                                $params[$k] = (string) $v;
                            }
                        }
                    }
                }

                if (preg_match('~\bmethod=[\'"]([^\'"]*)[\'"]~i', $attrs, $metMatch)) {
                    $method = strtolower($metMatch[1]);
                }

                $forms[] = [
                    'action' => $action,
                    'method' => $method,
                    'params' => $params,
                ];
            }
        }

        return $forms;
    }

    /**
     * @return list<string>
     */
    private function extractInputs(string $content): array
    {
        $inputs = [];

        if (preg_match_all('~<(?:input|select|textarea)\b[^>]*\bname=[\'"]([^\'"]+)[\'"]~i', $content, $matches)) {
            foreach ($matches[1] as $name) {
                $inputs[] = $name;
            }
        }

        $inputs = array_values(array_unique($inputs));
        sort($inputs, SORT_STRING);

        return $inputs;
    }

    /**
     * @return list<string>
     */
    private function extractXajax(string $content): array
    {
        $xajax = [];

        if (preg_match_all('~\bxajax_([a-zA-Z0-9_]+)\b~', $content, $matches)) {
            foreach ($matches[1] as $fn) {
                $xajax[] = 'xajax_' . $fn;
            }
        }

        if (preg_match_all('~\{([a-zA-Z0-9_]+Ajax)::getCall\(\)\}~', $content, $matches)) {
            foreach ($matches[1] as $cls) {
                $xajax[] = $cls;
            }
        }

        if (preg_match_all('~xajax\.call\(\s*[\'"]([^\'"]+)[\'"]~', $content, $matches)) {
            foreach ($matches[1] as $call) {
                $xajax[] = $call;
            }
        }

        $xajax = array_values(array_unique($xajax));
        sort($xajax, SORT_STRING);

        return $xajax;
    }
}
