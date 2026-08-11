<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final readonly class StructuralDiffer
{
    public function diff(AgentMapIndex $before, AgentMapIndex $after): TemporalDiffReport
    {
        $events = [
            ...$this->fileEvents($before, $after),
            ...$this->symbolEvents($before, $after),
            ...$this->methodEvents($before, $after),
            ...$this->relationEvents($before, $after),
        ];

        usort(
            $events,
            static fn (TemporalEvent $left, TemporalEvent $right): int => [$left->kind, $left->entityType, $left->entityId]
                <=> [$right->kind, $right->entityType, $right->entityId],
        );

        return new TemporalDiffReport($before->mapDigest(), $after->mapDigest(), $events);
    }

    /** @return list<TemporalEvent> */
    private function fileEvents(AgentMapIndex $before, AgentMapIndex $after): array
    {
        $old = $this->files($before);
        $new = $this->files($after);
        $events = [];

        foreach (array_diff_key($old, $new) as $path => $file) {
            $events[] = new TemporalEvent('file_removed', 'file', $path, ['path' => $path, 'sha256' => $file->sha256]);
        }
        foreach (array_diff_key($new, $old) as $path => $file) {
            $events[] = new TemporalEvent('file_added', 'file', $path, [], ['path' => $path, 'sha256' => $file->sha256]);
        }
        foreach (array_intersect_key($old, $new) as $path => $oldFile) {
            $newFile = $new[$path];
            if ($oldFile->sha256 !== $newFile->sha256) {
                $events[] = new TemporalEvent(
                    'file_changed',
                    'file',
                    $path,
                    ['path' => $path, 'sha256' => $oldFile->sha256],
                    ['path' => $path, 'sha256' => $newFile->sha256],
                );
            }
        }

        return $events;
    }

    /** @return list<TemporalEvent> */
    private function symbolEvents(AgentMapIndex $before, AgentMapIndex $after): array
    {
        $old = $this->symbols($before);
        $new = $this->symbols($after);
        $events = [];

        foreach (array_diff_key($old, $new) as $id => $item) {
            $events[] = new TemporalEvent(
                'symbol_removed',
                'symbol',
                $id,
                ['path' => $item['file']->path, 'signature' => $this->symbolSignature($item['symbol'])],
            );
        }
        foreach (array_diff_key($new, $old) as $id => $item) {
            $events[] = new TemporalEvent(
                'symbol_added',
                'symbol',
                $id,
                [],
                ['path' => $item['file']->path, 'signature' => $this->symbolSignature($item['symbol'])],
            );
        }
        foreach (array_intersect_key($old, $new) as $id => $oldItem) {
            $newItem = $new[$id];
            if ($oldItem['file']->path !== $newItem['file']->path) {
                $events[] = new TemporalEvent(
                    'symbol_moved',
                    'symbol',
                    $id,
                    ['path' => $oldItem['file']->path],
                    ['path' => $newItem['file']->path],
                );
            }

            $oldSignature = $this->symbolSignature($oldItem['symbol']);
            $newSignature = $this->symbolSignature($newItem['symbol']);
            if ($oldSignature !== $newSignature) {
                $events[] = new TemporalEvent(
                    'symbol_signature_changed',
                    'symbol',
                    $id,
                    ['path' => $oldItem['file']->path, 'signature' => $oldSignature],
                    ['path' => $newItem['file']->path, 'signature' => $newSignature],
                );
            }
        }

        return $events;
    }

    /** @return list<TemporalEvent> */
    private function methodEvents(AgentMapIndex $before, AgentMapIndex $after): array
    {
        $old = $this->methods($before);
        $new = $this->methods($after);
        $events = [];

        foreach (array_diff_key($old, $new) as $id => $item) {
            $events[] = new TemporalEvent(
                'method_removed',
                'method',
                $id,
                ['path' => $item['file']->path, 'signature' => $this->methodSignature($item['method'])],
            );
        }
        foreach (array_diff_key($new, $old) as $id => $item) {
            $events[] = new TemporalEvent(
                'method_added',
                'method',
                $id,
                [],
                ['path' => $item['file']->path, 'signature' => $this->methodSignature($item['method'])],
            );
        }
        foreach (array_intersect_key($old, $new) as $id => $oldItem) {
            $newItem = $new[$id];
            $oldSignature = $this->methodSignature($oldItem['method']);
            $newSignature = $this->methodSignature($newItem['method']);
            if ($oldSignature !== $newSignature) {
                $events[] = new TemporalEvent(
                    'method_signature_changed',
                    'method',
                    $id,
                    ['path' => $oldItem['file']->path, 'signature' => $oldSignature],
                    ['path' => $newItem['file']->path, 'signature' => $newSignature],
                );
            }
        }

        return $events;
    }

    /** @return list<TemporalEvent> */
    private function relationEvents(AgentMapIndex $before, AgentMapIndex $after): array
    {
        $old = $this->relations($before);
        $new = $this->relations($after);
        $events = [];

        foreach (array_diff_key($old, $new) as $id => $item) {
            $events[] = new TemporalEvent('relation_removed', 'relation', $id, $this->relationPayload($item));
        }
        foreach (array_diff_key($new, $old) as $id => $item) {
            $events[] = new TemporalEvent('relation_added', 'relation', $id, [], $this->relationPayload($item));
        }
        foreach (array_intersect_key($old, $new) as $id => $oldItem) {
            $newItem = $new[$id];
            if ($oldItem['count'] !== $newItem['count']) {
                $events[] = new TemporalEvent(
                    'relation_multiplicity_changed',
                    'relation',
                    $id,
                    $this->relationPayload($oldItem),
                    $this->relationPayload($newItem),
                );
            }
        }

        return $events;
    }

    /** @return array<string, FileEntry> */
    private function files(AgentMapIndex $map): array
    {
        $files = [];
        foreach ($map->files as $file) {
            $files[$file->path] = $file;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, array{file: FileEntry, symbol: SymbolEntry}> */
    private function symbols(AgentMapIndex $map): array
    {
        $symbols = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                $symbols[$symbol->id()] = ['file' => $file, 'symbol' => $symbol];
            }
        }
        ksort($symbols, SORT_STRING);

        return $symbols;
    }

    /** @return array<string, array{file: FileEntry, symbol: SymbolEntry, method: MethodEntry}> */
    private function methods(AgentMapIndex $map): array
    {
        $methods = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                foreach ($symbol->methods as $method) {
                    $methods[$symbol->methodId($method)] = ['file' => $file, 'symbol' => $symbol, 'method' => $method];
                }
            }
        }
        ksort($methods, SORT_STRING);

        return $methods;
    }

    /**
     * @return array<string, array{source_id: string, kind: string, target_ids: string, resolution: string, receiver_type: ?string, result_type: ?string, count: int}>
     */
    private function relations(AgentMapIndex $map): array
    {
        $relations = [];
        foreach ($map->relations as $relation) {
            $targets = $relation->targetIds;
            sort($targets, SORT_STRING);
            $payload = [
                'source_id' => $relation->sourceId,
                'kind' => $relation->kind,
                'target_ids' => implode(',', $targets),
                'resolution' => $relation->resolution,
                'receiver_type' => $relation->receiverType,
                'result_type' => $relation->resultType,
            ];
            $identity = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $id = 'relation-semantic:' . hash('sha256', $identity);
            if (!isset($relations[$id])) {
                $relations[$id] = [...$payload, 'count' => 0];
            }
            ++$relations[$id]['count'];
        }
        ksort($relations, SORT_STRING);

        return $relations;
    }

    private function symbolSignature(SymbolEntry $symbol): string
    {
        $parts = [$symbol->kind, ltrim($symbol->fqn, '\\')];
        if ($symbol->parameters !== []) {
            $parts[] = '(' . implode(', ', $symbol->displayParameters()) . ')';
        }
        if ($symbol->displayReturnType() !== null) {
            $parts[] = ': ' . $symbol->displayReturnType();
        }
        if ($symbol->extends !== []) {
            $extends = $symbol->extends;
            sort($extends, SORT_STRING);
            $parts[] = 'extends ' . implode(',', $extends);
        }
        if ($symbol->implements !== []) {
            $implements = $symbol->implements;
            sort($implements, SORT_STRING);
            $parts[] = 'implements ' . implode(',', $implements);
        }
        if ($symbol->uses !== []) {
            $uses = $symbol->uses;
            sort($uses, SORT_STRING);
            $parts[] = 'uses ' . implode(',', $uses);
        }
        if ($symbol->attributes !== []) {
            $attributes = $symbol->attributes;
            sort($attributes, SORT_STRING);
            $parts[] = 'attributes ' . implode(',', $attributes);
        }
        if ($symbol->templates !== []) {
            $templates = $symbol->templates;
            ksort($templates, SORT_STRING);
            $parts[] = 'templates ' . json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return implode(' ', $parts);
    }

    private function methodSignature(MethodEntry $method): string
    {
        $attributes = $method->attributes;
        sort($attributes, SORT_STRING);
        $modifiers = array_values(array_filter([
            $method->visibility,
            $method->static ? 'static' : null,
            $method->abstract ? 'abstract' : null,
            $method->final ? 'final' : null,
        ]));
        $signature = implode(' ', $modifiers)
            . ' ' . $method->name
            . '(' . implode(', ', $method->displayParameters()) . ')';
        if ($method->displayReturnType() !== null) {
            $signature .= ': ' . $method->displayReturnType();
        }
        if ($attributes !== []) {
            $signature .= ' attributes ' . implode(',', $attributes);
        }

        return trim($signature);
    }

    /**
     * @param array{source_id: string, kind: string, target_ids: string, resolution: string, receiver_type: ?string, result_type: ?string, count: int} $item
     * @return array<string, string|int|float|bool|null>
     */
    private function relationPayload(array $item): array
    {
        return $item;
    }
}
