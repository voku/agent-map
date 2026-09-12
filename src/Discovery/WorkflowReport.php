<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class WorkflowReport
{
    /**
     * @param list<array{symbol: string, file: string, line: int, kind: string, templates: list<string>}> $phpSymbols
     * @param list<TemplateInfo> $templates
     * @param list<TemplateInfo> $includedTemplates
     * @param list<TemplateInfo> $parentTemplates
     * @param list<array{action: ?string, method: ?string, target_symbol: ?string, target_file: ?string, params: array<string, string>}> $formActions
     * @param list<array{call: string, target_symbol: ?string, target_file: ?string}> $xajaxHandlers
     * @param list<string> $flowSteps
     */
    public function __construct(
        public string $query,
        public string $targetKind,
        public array $phpSymbols = [],
        public array $templates = [],
        public array $includedTemplates = [],
        public array $parentTemplates = [],
        public array $formActions = [],
        public array $xajaxHandlers = [],
        public array $flowSteps = [],
    ) {
    }

    /**
     * @return array{query: string, target_kind: string, php_symbols: list<array{symbol: string, file: string, line: int, kind: string, templates: list<string>}>, templates: list<array<string, mixed>>, included_templates: list<array<string, mixed>>, parent_templates: list<array<string, mixed>>, form_actions: list<array{action: ?string, method: ?string, target_symbol: ?string, target_file: ?string, params: array<string, string>}>, xajax_handlers: list<array{call: string, target_symbol: ?string, target_file: ?string}>, flow_steps: list<string>}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'target_kind' => $this->targetKind,
            'php_symbols' => $this->phpSymbols,
            'templates' => array_map(static fn (TemplateInfo $t): array => $t->toArray(), $this->templates),
            'included_templates' => array_map(static fn (TemplateInfo $t): array => $t->toArray(), $this->includedTemplates),
            'parent_templates' => array_map(static fn (TemplateInfo $t): array => $t->toArray(), $this->parentTemplates),
            'form_actions' => $this->formActions,
            'xajax_handlers' => $this->xajaxHandlers,
            'flow_steps' => $this->flowSteps,
        ];
    }
}
