<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use Throwable;
use voku\AgentMap\Discovery\WorkflowDiscovery;
use voku\AgentMap\Discovery\WorkflowReport;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;

final readonly class WorkflowCliApplication
{
    private MapArtifactPaths $artifacts;

    public function __construct(
        ?MapArtifactPaths $artifacts = null,
        ?string $defaultRoot = null,
    ) {
        $this->artifacts = $artifacts ?? MapArtifactPaths::forProject($defaultRoot ?? (getcwd() ?: '.'));
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        $command = $argv[1] ?? null;
        if ($command === 'workflow') {
            return true;
        }

        return $command === 'help' && ($argv[2] ?? null) === 'workflow';
    }

    /** @param list<string> $argv */
    public function shouldAppendToGeneralHelp(array $argv): bool
    {
        return count($argv) === 2 && in_array($argv[1], ['help', '-h', '--help'], true);
    }

    public function helpOverview(): string
    {
        return <<<'TEXT'

Workflow discovery:
  workflow   Discover web workflows linking PHP controllers, templates, forms, and AJAX handlers

Run `agent-map help workflow` for details.
TEXT;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? '';
            if ($command === 'help') {
                echo $this->help();
                return 0;
            }

            return $this->workflow(array_slice($argv, 2));
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function workflow(array $tokens): int
    {
        $parsed = $this->parse($tokens);
        if ($parsed['help']) {
            echo $this->help();
            return 0;
        }

        $query = $parsed['argument'];
        if ($query === null || $query === '') {
            throw new InvalidArgumentException('Workflow command requires a target (PHP class/method, template, or keyword).');
        }

        $indexPath = $parsed['options']['index'] ?? $this->artifacts->indexJson();
        $map = (new IndexReader())->read($indexPath);

        $report = (new WorkflowDiscovery())->discover($map, $query);

        $format = $parsed['options']['format'] ?? 'text';
        echo $this->render($report, $format);

        return 0;
    }

    private function render(WorkflowReport $report, string $format): string
    {
        return match ($format) {
            'json' => json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'toon' => Toon::encode($report->toArray()) . "\n",
            'markdown' => $this->renderMarkdown($report),
            default => $this->renderText($report),
        };
    }

    private function renderText(WorkflowReport $report): string
    {
        $out = "Workflow Discovery: {$report->query}\n";
        $out .= "Target kind: {$report->targetKind}\n\n";

        if ($report->flowSteps !== []) {
            $out .= "Workflow Flow:\n";
            foreach ($report->flowSteps as $step) {
                $out .= "  {$step}\n";
            }
            $out .= "\n";
        }

        if ($report->phpSymbols !== []) {
            $out .= "PHP Controllers / Views:\n";
            foreach ($report->phpSymbols as $sym) {
                $out .= "  {$sym['symbol']} ({$sym['file']}:{$sym['line']})\n";
                if ($sym['templates'] !== []) {
                    $out .= "    Templates: " . implode(', ', $sym['templates']) . "\n";
                }
            }
            $out .= "\n";
        }

        if ($report->templates !== []) {
            $out .= "Primary Templates:\n";
            foreach ($report->templates as $tpl) {
                $out .= "  {$tpl->name} [{$tpl->engine}] ({$tpl->path})\n";
                if ($tpl->includes !== []) {
                    $out .= "    Includes: " . implode(', ', $tpl->includes) . "\n";
                }
                if ($tpl->inputs !== []) {
                    $previewInputs = array_slice($tpl->inputs, 0, 8);
                    $more = count($tpl->inputs) - count($previewInputs);
                    $out .= "    Inputs: " . implode(', ', $previewInputs) . ($more > 0 ? " (+{$more} more)" : "") . "\n";
                }
                if ($tpl->xajax !== []) {
                    $out .= "    AJAX: " . implode(', ', $tpl->xajax) . "\n";
                }
            }
            $out .= "\n";
        }

        if ($report->includedTemplates !== []) {
            $out .= "Sub-Templates / Partials:\n";
            foreach ($report->includedTemplates as $tpl) {
                $out .= "  {$tpl->name} ({$tpl->path})\n";
            }
            $out .= "\n";
        }

        if ($report->parentTemplates !== []) {
            $out .= "Included By (Parent Templates):\n";
            foreach ($report->parentTemplates as $tpl) {
                $out .= "  {$tpl->name} ({$tpl->path})\n";
            }
            $out .= "\n";
        }

        if ($report->formActions !== []) {
            $out .= "Form Actions:\n";
            foreach ($report->formActions as $fa) {
                $target = $fa['target_symbol'] !== null ? " -> {$fa['target_symbol']} ({$fa['target_file']})" : '';
                $out .= "  [{$fa['method']}] " . ($fa['action'] ?? '(current URL)') . "{$target}\n";
            }
            $out .= "\n";
        }

        if ($report->xajaxHandlers !== []) {
            $out .= "AJAX Handlers:\n";
            foreach ($report->xajaxHandlers as $xh) {
                $target = $xh['target_symbol'] !== null ? " -> {$xh['target_symbol']} ({$xh['target_file']})" : ' (unresolved)';
                $out .= "  {$xh['call']}{$target}\n";
            }
            $out .= "\n";
        }

        return $out;
    }

    private function renderMarkdown(WorkflowReport $report): string
    {
        $out = "# Workflow Discovery: `{$report->query}`\n\n";
        $out .= "**Target Kind:** `{$report->targetKind}`\n\n";

        if ($report->flowSteps !== []) {
            $out .= "## Flow\n\n";
            foreach ($report->flowSteps as $step) {
                $out .= "- {$step}\n";
            }
            $out .= "\n";
        }

        if ($report->phpSymbols !== []) {
            $out .= "## PHP Views & Controllers\n\n";
            foreach ($report->phpSymbols as $sym) {
                $out .= "- **`{$sym['symbol']}`** (`{$sym['file']}:{$sym['line']}`)\n";
                if ($sym['templates'] !== []) {
                    $out .= "  - Templates: `" . implode('`, `', $sym['templates']) . "`\n";
                }
            }
            $out .= "\n";
        }

        if ($report->templates !== []) {
            $out .= "## Templates\n\n";
            foreach ($report->templates as $tpl) {
                $out .= "### `{$tpl->name}` ({$tpl->engine})\n";
                $out .= "- Path: `{$tpl->path}`\n";
                if ($tpl->includes !== []) {
                    $out .= "- Includes: `" . implode('`, `', $tpl->includes) . "`\n";
                }
                if ($tpl->inputs !== []) {
                    $out .= "- Inputs: `" . implode('`, `', array_slice($tpl->inputs, 0, 10)) . "`\n";
                }
                if ($tpl->xajax !== []) {
                    $out .= "- AJAX: `" . implode('`, `', $tpl->xajax) . "`\n";
                }
            }
            $out .= "\n";
        }

        return $out;
    }

    private function help(): string
    {
        return <<<'HELP'
agent-map workflow - discover web workflows across PHP and template files

Usage:
  agent-map workflow <target> [--index=<path>] [--format=text|json|markdown|toon]

Arguments:
  <target>  PHP class, method, template file (*.tpl, *.twig), or feature keyword

Options:
  --index=<path>   Path to php-symbols.json index [default: auto-detected]
  --format=<type>  Output format: text, json, markdown, toon [default: text]

HELP;
    }

    /**
     * @param list<string> $tokens
     * @return array{argument: ?string, options: array<string, string>, help: bool}
     */
    private function parse(array $tokens): array
    {
        $argument = null;
        $options = [];
        $help = false;

        foreach ($tokens as $token) {
            if ($token === '-h' || $token === '--help') {
                $help = true;
                continue;
            }
            if (str_starts_with($token, '--')) {
                $eq = strpos($token, '=');
                if ($eq !== false) {
                    $key = substr($token, 2, $eq - 2);
                    $val = substr($token, $eq + 1);
                    $options[$key] = $val;
                } else {
                    $options[substr($token, 2)] = '1';
                }
                continue;
            }
            if ($argument === null) {
                $argument = $token;
            }
        }

        return [
            'argument' => $argument,
            'options' => $options,
            'help' => $help,
        ];
    }
}
