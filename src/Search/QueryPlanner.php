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

    /**
     * @param list<string> $languages ISO language codes, defaults to ['en', 'de']
     *
     * @return list<string>
     */
    public static function getStopWords(array $languages = ['en', 'de']): array
    {
        return StopWordIndex::words($languages);
    }

    private function isStructural(string $candidate): bool
    {
        if (StopWordIndex::contains($candidate)) {
            return false;
        }
        if (str_contains($candidate, '::') || str_contains($candidate, '\\')) {
            return true;
        }
        if (mb_strlen($candidate) < 2) {
            return false;
        }

        // CamelCase or snake_case with a capital: how identifiers look, not how sentences do.
        // Short identifiers with uppercase letters or digits (e.g., D3, AD, OU, IP, UI, DB, M365, S3, V1) are structural.
        return preg_match('/[a-z][A-Z]/', $candidate) === 1
            || (preg_match('/^[A-Z0-9_]{2,4}$/', $candidate) === 1 && preg_match('/[A-Z0-9]/', $candidate) === 1)
            || (preg_match('/^[A-Z]/', $candidate) === 1 && preg_match('/[a-z0-9]/i', $candidate) === 1)
            || str_contains($candidate, '_');
    }
}