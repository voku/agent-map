<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;

final readonly class PhpTokenSymbolExtractor
{
    /**
     * @return list<SymbolEntry>
     */
    public function extract(string $file, string $code): array
    {
        $tokens = token_get_all($code);
        $symbols = [];
        $classRanges = [];

        foreach ($tokens as $index => $token) {
            if (!$this->isToken($token, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                continue;
            }

            if ($this->isToken($token, [T_CLASS]) && $this->previousMeaningfulTokenId($tokens, $index) === T_NEW) {
                continue;
            }

            $nameIndex = $this->nextTokenIndex($tokens, $index + 1, [T_STRING]);
            if ($nameIndex === null) {
                continue;
            }

            $open = $this->nextStringIndex($tokens, $nameIndex + 1, '{');
            $close = $open === null ? null : $this->matchingBraceIndex($tokens, $open);
            $name = $this->text($tokens[$nameIndex]);
            $lineStart = $this->line($token);
            $lineEnd = $close === null ? $lineStart : $this->line($tokens[$close]);
            $kind = $this->kind($token);
            $namespace = $this->namespaceAt($tokens, $index);
            $methods = $open === null || $close === null ? [] : $this->methods($tokens, $open + 1, $close - 1);

            if ($open !== null && $close !== null) {
                $classRanges[] = [$open, $close];
            }

            $symbols[] = new SymbolEntry($kind, $name, $this->fqn($namespace, $name), $lineStart, $lineEnd, $methods);
        }

        foreach ($tokens as $index => $token) {
            if (!$this->isToken($token, [T_FUNCTION]) || $this->isInsideRange($index, $classRanges)) {
                continue;
            }

            $nameIndex = $this->nextTokenIndex($tokens, $index + 1, [T_STRING]);
            if ($nameIndex === null) {
                continue;
            }

            $name = $this->text($tokens[$nameIndex]);
            $lineStart = $this->line($token);
            $namespace = $this->namespaceAt($tokens, $index);
            $symbols[] = new SymbolEntry('function', $name, $this->fqn($namespace, $name), $lineStart, $lineStart);
        }

        return $symbols;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function namespaceAt(array $tokens, int $targetIndex): string
    {
        $namespace = '';
        for ($index = 0; $index <= $targetIndex; ++$index) {
            $token = $tokens[$index] ?? null;
            if (!$this->isToken($token, [T_NAMESPACE])) {
                continue;
            }

            if (!$this->isNamespaceDeclaration($tokens, $index)) {
                continue;
            }

            $name = '';
            for ($i = $index + 1, $count = count($tokens); $i < $count; ++$i) {
                $current = $tokens[$i];
                if ($current === ';' || $current === '{') {
                    $namespace = trim($name, '\\');
                    break;
                }

                if (is_array($current) && in_array($current[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $name .= $current[1];
                }
            }
        }

        return $namespace;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function isNamespaceDeclaration(array $tokens, int $index): bool
    {
        $next = $this->nextMeaningfulToken($tokens, $index + 1);

        return $next === '{' || $this->isToken($next, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]);
    }

    /**
     * @param list<mixed> $tokens
     * @return list<MethodEntry>
     */
    private function methods(array $tokens, int $start, int $end): array
    {
        $methods = [];
        $depth = 0;
        $interpolationDepth = 0;
        for ($i = $start; $i <= $end; ++$i) {
            $token = $tokens[$i];
            if ($this->isToken($token, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$interpolationDepth;
                continue;
            }

            if ($token === '}' && $interpolationDepth > 0) {
                --$interpolationDepth;
                continue;
            }

            if ($token === '{') {
                ++$depth;
                continue;
            }

            if ($token === '}') {
                --$depth;
                continue;
            }

            if ($depth !== 0 || !$this->isToken($token, [T_FUNCTION])) {
                continue;
            }

            $nameIndex = $this->nextTokenIndex($tokens, $i + 1, [T_STRING]);
            if ($nameIndex === null) {
                continue;
            }

            $methods[] = new MethodEntry(
                $this->text($tokens[$nameIndex]),
                $this->visibilityBefore($tokens, $i),
                $this->line($token),
            );
        }

        return $methods;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function visibilityBefore(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];
            if ($this->isToken($token, [T_PUBLIC])) {
                return 'public';
            }
            if ($this->isToken($token, [T_PROTECTED])) {
                return 'protected';
            }
            if ($this->isToken($token, [T_PRIVATE])) {
                return 'private';
            }
            if ($token === ';' || $token === '{' || $token === '}') {
                break;
            }
        }

        return 'public';
    }

    /**
     * @param list<mixed> $tokens
     * @param list<int> $ids
     */
    private function nextTokenIndex(array $tokens, int $start, array $ids): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; ++$i) {
            if ($this->isToken($tokens[$i], $ids)) {
                return $i;
            }

            if (!$this->isSkippable($tokens[$i]) && $tokens[$i] !== '&') {
                return null;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function nextStringIndex(array $tokens, int $start, string $text): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; ++$i) {
            if ($tokens[$i] === $text) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function matchingBraceIndex(array $tokens, int $open): ?int
    {
        $depth = 0;
        $interpolationDepth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; ++$i) {
            if ($this->isToken($tokens[$i], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$interpolationDepth;
                continue;
            }

            if ($tokens[$i] === '}' && $interpolationDepth > 0) {
                --$interpolationDepth;
                continue;
            }

            if ($tokens[$i] === '{') {
                ++$depth;
            } elseif ($tokens[$i] === '}') {
                --$depth;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function previousMeaningfulTokenId(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            if ($this->isSkippable($tokens[$i])) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i][0] : null;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function nextMeaningfulToken(array $tokens, int $index): mixed
    {
        for ($i = $index, $count = count($tokens); $i < $count; ++$i) {
            if ($this->isSkippable($tokens[$i])) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    private function isInsideRange(int $index, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($index > $start && $index < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $token
     * @param list<int> $ids
     */
    private function isToken(mixed $token, array $ids): bool
    {
        return is_array($token) && in_array($token[0], $ids, true);
    }

    private function isSkippable(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true);
    }

    private function kind(mixed $token): string
    {
        return match (is_array($token) ? $token[0] : null) {
            T_INTERFACE => 'interface',
            T_TRAIT => 'trait',
            T_ENUM => 'enum',
            default => 'class',
        };
    }

    private function text(mixed $token): string
    {
        return is_array($token) ? (string) $token[1] : (string) $token;
    }

    private function line(mixed $token): int
    {
        return is_array($token) ? (int) $token[2] : 0;
    }

    private function fqn(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}
