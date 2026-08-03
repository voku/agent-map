<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class ScopeInspection
{
    /**
     * @param list<CallHint> $calls
     * @param list<TableHint> $tables
     * @param list<TemplateHint> $templates
     */
    public function __construct(
        public ScopeTarget $target,
        public array $calls,
        public array $tables,
        public array $templates,
        public int $callsOmitted = 0,
        public int $tablesOmitted = 0,
        public int $templatesOmitted = 0,
    ) {
    }

    /**
     * @return array{target: array{kind: string, label: string, file: string, line_start: int, line_end: int, source_id: ?string}, calls: list<array{kind: string, label: string, line: int, target_ids: list<string>, resolution: string, receiver_type: ?string, result_type: ?string}>, calls_omitted: int, tables: list<array{table: string, action: string, line: int}>, tables_omitted: int, templates: list<array{path: string, line: int}>, templates_omitted: int}
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target->toArray(),
            'calls' => array_map(static fn (CallHint $call): array => $call->toArray(), $this->calls),
            'calls_omitted' => $this->callsOmitted,
            'tables' => array_map(static fn (TableHint $table): array => $table->toArray(), $this->tables),
            'tables_omitted' => $this->tablesOmitted,
            'templates' => array_map(static fn (TemplateHint $template): array => $template->toArray(), $this->templates),
            'templates_omitted' => $this->templatesOmitted,
        ];
    }
}
