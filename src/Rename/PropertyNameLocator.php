<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\VarLikeIdentifier;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps one property declaration/access identity to exact source tokens. */
final class PropertyNameLocator
{
    /** @var array<string, array<int, Node>> */
    private array $astByPath = [];

    /** @var array<string, string> */
    private array $sourceByPath = [];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return array{start_file_pos: int, end_file_pos: int, line_start: int, line_end: int, actual: string, visibility: string, static: bool, promoted: bool, hooks: bool}
     */
    public function declaration(string $path, string $ownerFqn, string $propertyName): array
    {
        $class = $this->classLike($path, $ownerFqn);
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== $propertyName) {
                    continue;
                }
                $token = $this->token($path, $item->name);

                return [
                    ...$token,
                    'visibility' => $property->isPrivate() ? 'private' : ($property->isProtected() ? 'protected' : 'public'),
                    'static' => $property->isStatic(),
                    'promoted' => false,
                    'hooks' => $property->hooks !== [],
                ];
            }
        }

        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== '__construct') {
                continue;
            }
            foreach ($method->params as $parameter) {
                if ($parameter->flags === 0 || !$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                    continue;
                }
                if ($parameter->var->name !== $propertyName) {
                    continue;
                }
                $token = $this->token($path, $parameter->var);

                return [
                    ...$token,
                    'visibility' => ($parameter->flags & Modifiers::PRIVATE) !== 0
                        ? 'private'
                        : ((($parameter->flags & Modifiers::PROTECTED) !== 0) ? 'protected' : 'public'),
                    'static' => false,
                    'promoted' => true,
                    'hooks' => $parameter->hooks !== [],
                ];
            }
        }

        throw new RuntimeException('Property declaration not found in current source: ' . $ownerFqn . '::$' . $propertyName);
    }

    public function replacementExists(string $path, string $ownerFqn, string $replacementName): bool
    {
        $class = $this->classLike($path, $ownerFqn);
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() === $replacementName) {
                    return true;
                }
            }
        }
        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== '__construct') {
                continue;
            }
            foreach ($method->params as $parameter) {
                if ($parameter->flags !== 0
                    && $parameter->var instanceof Variable
                    && is_string($parameter->var->name)
                    && $parameter->var->name === $replacementName
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{start_file_pos: int, end_file_pos: int, line_start: int, line_end: int, actual: string} */
    public function access(string $path, int $lineStart, int $lineEnd, string $expectedName): array
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            $name = $this->accessName($node);
            if ($name === null) {
                continue;
            }
            if ($name->toString() !== $expectedName) {
                continue;
            }
            $matches[] = $this->token($path, $name);
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map property access "%s" to exactly one token at %s:%d-%d; found %d candidate(s).',
                $expectedName,
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        return $matches[0];
    }

    public function isDynamicAccess(string $path, int $lineStart, int $lineEnd): bool
    {
        foreach ($this->nodes($path) as $node) {
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            if ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
                return !$node->name instanceof Identifier;
            }
            if ($node instanceof StaticPropertyFetch) {
                return !$node->name instanceof VarLikeIdentifier;
            }
        }

        return false;
    }

    public function replacementToken(string $actual, string $replacementName): string
    {
        return str_starts_with($actual, '$') ? '$' . $replacementName : $replacementName;
    }

    private function accessName(Node $node): Identifier|VarLikeIdentifier|null
    {
        if ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            return $node->name instanceof Identifier ? $node->name : null;
        }
        if ($node instanceof StaticPropertyFetch) {
            return $node->name instanceof VarLikeIdentifier ? $node->name : null;
        }

        return null;
    }

    private function classLike(string $path, string $ownerFqn): ClassLike
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof ClassLike || $node->name === null) {
                continue;
            }
            $resolved = $node->namespacedName instanceof Name
                ? ltrim($node->namespacedName->toString(), '\\')
                : $node->name->toString();
            if (strcasecmp($resolved, ltrim($ownerFqn, '\\')) === 0) {
                $matches[] = $node;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf('Cannot map property owner %s to exactly one class-like declaration in %s.', $ownerFqn, $path));
        }

        return $matches[0];
    }

    /** @return array{start_file_pos: int, end_file_pos: int, line_start: int, line_end: int, actual: string} */
    private function token(string $path, Node $node): array
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose byte positions for property rename token in ' . $path . '.');
        }
        $actual = substr($this->source($path), $start, $end - $start + 1);
        if ($actual === '') {
            throw new RuntimeException('Parser exposed an empty property rename token in ' . $path . '.');
        }

        return [
            'start_file_pos' => $start,
            'end_file_pos' => $end,
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'actual' => $actual,
        ];
    }

    /** @return list<Node> */
    private function nodes(string $path): array
    {
        $nodes = [];
        foreach ($this->ast($path) as $node) {
            $this->appendNode($nodes, $node);
        }

        return $nodes;
    }

    /** @return array<int, Node> */
    private function ast(string $path): array
    {
        if (!isset($this->astByPath[$path])) {
            $this->astByPath[$path] = PhpCodeParser::getAstFromString($this->source($path));
        }

        return $this->astByPath[$path];
    }

    /** @param list<Node> $nodes */
    private function appendNode(array &$nodes, Node $node): void
    {
        $nodes[] = $node;
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->appendNode($nodes, $child);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->appendNode($nodes, $item);
                }
            }
        }
    }

    private function source(string $path): string
    {
        if (!isset($this->sourceByPath[$path])) {
            $absolute = rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
            $source = file_get_contents($absolute);
            if (!is_string($source)) {
                throw new RuntimeException('Cannot read property rename source file: ' . $path);
            }
            $this->sourceByPath[$path] = $source;
        }

        return $this->sourceByPath[$path];
    }
}
