<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;

final readonly class DeclarationSignature
{
    public function symbol(SymbolEntry $symbol): string
    {
        $parts = [$symbol->kind, ltrim($symbol->fqn, '\\')];
        if ($symbol->parameters !== []) {
            $parts[] = '(' . implode(', ', $symbol->displayParameters()) . ')';
        }
        if ($symbol->displayReturnType() !== null) {
            $parts[] = ': ' . $symbol->displayReturnType();
        }
        foreach ([
            'extends' => $symbol->extends,
            'implements' => $symbol->implements,
            'uses' => $symbol->uses,
            'attributes' => $symbol->attributes,
        ] as $label => $values) {
            if ($values === []) {
                continue;
            }
            sort($values, SORT_STRING);
            $parts[] = $label . ' ' . implode(',', $values);
        }
        if ($symbol->templates !== []) {
            $templates = $symbol->templates;
            ksort($templates, SORT_STRING);
            $parts[] = 'templates ' . json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return implode(' ', $parts);
    }

    public function method(MethodEntry $method): string
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
}
