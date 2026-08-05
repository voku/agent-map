<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

/**
 * Splits a query into the part the map can answer exactly and the part it cannot.
 *
 * A sentence typed by a human ("how are retry timeouts handled") has no business being handed to an
 * exact symbol lookup and coming back with a structural rank: that would dress a guess up as a map
 * fact. Only symbol-shaped terms feed the structural channel; the whole query feeds lexical and,
 * later, semantic search.
 */
final readonly class QueryPlanner
{
    /** Words that look like identifiers but are how people phrase questions. */
    public const array STOP_WORDS = [
        'how', 'what', 'where', 'which', 'when', 'why', 'who', 'does', 'do', 'is', 'are', 'the',
        'and', 'or', 'for', 'with', 'from', 'into', 'this', 'that', 'handled', 'handle', 'work',
        'works', 'used', 'use', 'code', 'class', 'method', 'function', 'file',
    ];

    /**
     * @return array{structural_terms: list<string>, free_text: string}
     */
    public function plan(string $query): array
    {
        $terms = [];

        // Class::method, qualified names, namespaced identifiers - unambiguous structural shapes.
        if (preg_match_all('/[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:::[A-Za-z_][A-Za-z0-9_]*)?/u', $query, $matches) === false) {
            return ['structural_terms' => [], 'free_text' => $query];
        }

        foreach ($matches[0] as $candidate) {
            if ($this->isStructural($candidate)) {
                $terms[$candidate] = true;
            }
        }

        return [
            'structural_terms' => array_keys($terms),
            'free_text'        => $query,
        ];
    }

    private function isStructural(string $candidate): bool
    {
        if (in_array(strtolower($candidate), self::STOP_WORDS, true)) {
            return false;
        }
        if (str_contains($candidate, '::') || str_contains($candidate, '\\')) {
            return true;
        }
        if (mb_strlen($candidate) < 4) {
            return false;
        }

        // CamelCase or snake_case with a capital: how identifiers look, not how sentences do.
        return preg_match('/[a-z][A-Z]/', $candidate) === 1
            || (preg_match('/^[A-Z]/', $candidate) === 1 && preg_match('/[a-z]/', $candidate) === 1)
            || str_contains($candidate, '_');
    }
}
