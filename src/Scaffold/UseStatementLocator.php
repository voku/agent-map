<?php

declare(strict_types=1);

namespace voku\AgentMap\Scaffold;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final readonly class UseStatementLocator
{
    private const BUILTIN_TYPES = [
        'string', 'int', 'float', 'bool', 'array', 'iterable', 'callable',
        'void', 'never', 'null', 'object', 'false', 'true', 'mixed',
        'self', 'static', 'parent',
        'list', 'scalar', 'resource', 'numeric', 'array-key',
        'class-string', 'callable-string', 'non-empty-string',
        'positive-int', 'negative-int', 'non-negative-int', 'non-positive-int',
    ];

    public function isBuiltinType(string $type): bool
    {
        $clean = ltrim(strtolower(trim($type)), '?');
        if (in_array($clean, self::BUILTIN_TYPES, true)) {
            return true;
        }

        if (str_contains($clean, '|') || str_contains($clean, '&')) {
            $parts = preg_split('/[|&]/', $clean);
            if ($parts !== false) {
                foreach ($parts as $part) {
                    if (!$this->isBuiltinType($part)) {
                        return false;
                    }
                }

                return true;
            }
        }

        if (($pos = strpos($clean, '<')) !== false) {
            $base = substr($clean, 0, $pos);
            if (in_array($base, self::BUILTIN_TYPES, true)) {
                return true;
            }
        }

        while (str_ends_with($clean, '[]')) {
            $clean = substr($clean, 0, -2);
            if (in_array($clean, self::BUILTIN_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts individual class FQNs that might need importing from a type expression.
     *
     * Handles unions (A|B), intersections (A&B), generics (Collection<A, B>, array<K, V>),
     * array brackets (A[]), and nullable (?A).
     *
     * @return list<string>
     */
    public function extractClassTypes(string $typeExpression): array
    {
        $typeExpression = trim($typeExpression);
        if ($typeExpression === '') {
            return [];
        }

        $tokens = preg_split('/[|&<>,()\[\]\s]+/', $typeExpression, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }

        $classes = [];
        foreach ($tokens as $token) {
            $token = ltrim(trim($token), '?\\');
            if ($token === '' || $this->isBuiltinType($token)) {
                continue;
            }
            if (is_numeric($token) || str_starts_with($token, "'") || str_starts_with($token, '"')) {
                continue;
            }
            if (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/', $token) === 1) {
                $classes[] = $token;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return array{start: int, end: int, expected: string, insertion: string, line: int}|null
     */
    public function findUseInsertion(string $source, string $typeFqn): ?array
    {
        $typeFqn = ltrim(trim($typeFqn), '\\');
        if ($typeFqn === '' || $this->isBuiltinType($typeFqn) || preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/', $typeFqn) !== 1) {
            return null;
        }

        $shortName = ($pos = strrpos($typeFqn, '\\')) !== false ? substr($typeFqn, $pos + 1) : $typeFqn;

        $stmts = PhpCodeParser::getAstFromString($source);
        $fileNamespace = null;
        $lastUse = null;
        $namespaceNode = null;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespaceNode = $stmt;
                $fileNamespace = $stmt->name?->toString();
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Use_) {
                        $lastUse = $inner;
                        foreach ($inner->uses as $use) {
                            if ($use->name->toString() === $typeFqn) {
                                return null; // Already imported
                            }
                            $existingShort = $use->alias?->toString() ?? $use->name->getLast();
                            if ($existingShort === $shortName) {
                                return null; // Name collision or already covered
                            }
                        }
                    }
                }
            } elseif ($stmt instanceof Use_) {
                $lastUse = $stmt;
                foreach ($stmt->uses as $use) {
                    if ($use->name->toString() === $typeFqn) {
                        return null; // Already imported
                    }
                    $existingShort = $use->alias?->toString() ?? $use->name->getLast();
                    if ($existingShort === $shortName) {
                        return null; // Name collision or already covered
                    }
                }
            }
        }

        // Same namespace check
        $typeNamespace = ($pos = strrpos($typeFqn, '\\')) !== false ? substr($typeFqn, 0, $pos) : null;
        if ($fileNamespace !== null && $fileNamespace === $typeNamespace) {
            return null; // In same namespace, no import needed
        }

        // Global class in global namespace
        if ($fileNamespace === null && !str_contains($typeFqn, '\\')) {
            return null;
        }

        if ($lastUse !== null) {
            $endPos = $lastUse->getEndFilePos();
            $nextNewline = strpos($source, "\n", $endPos);
            $insertPos = $nextNewline === false ? strlen($source) : $nextNewline + 1;
            $insertion = 'use ' . $typeFqn . ";\n";

            return [
                'start' => $insertPos,
                'end' => $insertPos,
                'expected' => '',
                'insertion' => $insertion,
                'line' => $lastUse->getEndLine() + 1,
            ];
        }

        if ($namespaceNode !== null) {
            $semicolonPos = strpos($source, ';', $namespaceNode->getStartFilePos());
            if ($semicolonPos !== false) {
                $nextNewline = strpos($source, "\n", $semicolonPos);
                $insertPos = $nextNewline === false ? strlen($source) : $nextNewline + 1;
                $insertion = "\nuse " . $typeFqn . ";\n";

                return [
                    'start' => $insertPos,
                    'end' => $insertPos,
                    'expected' => '',
                    'insertion' => $insertion,
                    'line' => $namespaceNode->getStartLine() + 1,
                ];
            }
        }

        // Fallback: after <?php declaration
        $phpPos = strpos($source, "<?php");
        if ($phpPos !== false) {
            $nextNewline = strpos($source, "\n", $phpPos);
            $insertPos = $nextNewline === false ? strlen($source) : $nextNewline + 1;
            $insertion = "\nuse " . $typeFqn . ";\n";

            return [
                'start' => $insertPos,
                'end' => $insertPos,
                'expected' => '',
                'insertion' => $insertion,
                'line' => 2,
            ];
        }

        return null;
    }
}
