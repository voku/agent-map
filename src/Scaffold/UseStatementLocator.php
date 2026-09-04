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
    ];

    public function isBuiltinType(string $type): bool
    {
        $clean = ltrim(strtolower(trim($type)), '?');
        return in_array($clean, self::BUILTIN_TYPES, true);
    }

    /**
     * @return array<string, string> maps short class name => FQN
     */
    public function importedTypes(string $source): array
    {
        $imported = [];
        $stmts = PhpCodeParser::getAstFromString($source);
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Use_) {
                        foreach ($inner->uses as $use) {
                            $shortName = $use->alias?->toString() ?? $use->name->getLast();
                            $imported[$shortName] = $use->name->toString();
                        }
                    }
                }
            } elseif ($stmt instanceof Use_) {
                foreach ($stmt->uses as $use) {
                    $shortName = $use->alias?->toString() ?? $use->name->getLast();
                    $imported[$shortName] = $use->name->toString();
                }
            }
        }

        return $imported;
    }

    /**
     * @return array{start: int, end: int, expected: string, insertion: string, line: int}|null
     */
    public function findUseInsertion(string $source, string $typeFqn): ?array
    {
        $typeFqn = ltrim(trim($typeFqn), '\\');
        if ($typeFqn === '' || $this->isBuiltinType($typeFqn)) {
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
