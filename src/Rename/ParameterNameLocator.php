<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps one resolved method parameter, its lexical uses, and named call arguments to exact source tokens. */
final class ParameterNameLocator
{
    /** @var array<string, array<int, Node>> */
    private array $astByPath = [];

    /** @var array<string, string> */
    private array $sourceByPath = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string, line: int} */
    public function declaration(
        string $path,
        int $lineStart,
        int $lineEnd,
        string $methodName,
        string $parameterName,
        int $parameterIndex,
    ): array {
        $method = $this->method($path, $lineStart, $lineEnd, $methodName);
        $parameter = $method->params[$parameterIndex] ?? null;
        if (!$parameter instanceof Param || !$parameter->var instanceof Variable || !is_string($parameter->var->name) || $parameter->var->name !== $parameterName) {
            throw new RuntimeException(sprintf(
                'Cannot map parameter declaration "$%s" at index %d on %s:%d-%d.',
                $parameterName,
                $parameterIndex,
                $path,
                $lineStart,
                $lineEnd,
            ));
        }

        return $this->variablePosition($path, $parameter->var, $parameterName);
    }

    /**
     * @return array{
     *   references: list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}>,
     *   replacement_references: list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}>
     * }
     */
    public function references(
        string $path,
        int $lineStart,
        int $lineEnd,
        string $methodName,
        string $parameterName,
        string $replacementName,
    ): array {
        $method = $this->method($path, $lineStart, $lineEnd, $methodName);
        $references = [];
        $replacementReferences = [];
        foreach ($method->stmts ?? [] as $statement) {
            $this->collectMethodVariables($path, $statement, $parameterName, $replacementName, $references, $replacementReferences);
        }

        return ['references' => $references, 'replacement_references' => $replacementReferences];
    }

    /**
     * @return array{
     *   named: list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}>,
     *   replacement_named: list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}>,
     *   has_unpack: bool
     * }
     */
    public function call(
        string $path,
        int $lineStart,
        int $lineEnd,
        ?string $methodName,
        string $parameterName,
        string $replacementName,
    ): array {
        $calls = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall && !$node instanceof StaticCall) {
                continue;
            }
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            if ($methodName !== null && (!$node->name instanceof Identifier || strcasecmp($node->name->toString(), $methodName) !== 0)) {
                continue;
            }
            $calls[] = $node;
        }

        if ($calls === []) {
            throw new RuntimeException(sprintf('Cannot map call evidence to a call at %s:%d-%d.', $path, $lineStart, $lineEnd));
        }

        $inspected = array_map(
            fn (MethodCall|NullsafeMethodCall|StaticCall $call): array => $this->inspectCall($path, $call, $parameterName, $replacementName),
            $calls,
        );
        if (count($calls) > 1) {
            foreach ($inspected as $call) {
                if ($call['named'] !== [] || $call['has_unpack']) {
                    throw new RuntimeException(sprintf(
                        'Cannot map named/unpacked call evidence to exactly one call at %s:%d-%d; found %d candidate(s).',
                        $path,
                        $lineStart,
                        $lineEnd,
                        count($calls),
                    ));
                }
            }

            return ['named' => [], 'replacement_named' => [], 'has_unpack' => false];
        }

        return $inspected[0];
    }

    /** @return array{named: list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}>, replacement_named: list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}>, has_unpack: bool} */
    private function inspectCall(
        string $path,
        MethodCall|NullsafeMethodCall|StaticCall $call,
        string $parameterName,
        string $replacementName,
    ): array {
        $named = [];
        $replacementNamed = [];
        $hasUnpack = false;
        foreach ($call->args as $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }
            if ($argument->unpack) {
                $hasUnpack = true;
            }
            if (!$argument->name instanceof Identifier) {
                continue;
            }
            $actual = $argument->name->toString();
            if ($actual === $parameterName) {
                $named[] = $this->identifierPosition($path, $argument->name, $parameterName);
            }
            if ($actual === $replacementName) {
                $replacementNamed[] = $this->identifierPosition($path, $argument->name, $replacementName);
            }
        }

        return ['named' => $named, 'replacement_named' => $replacementNamed, 'has_unpack' => $hasUnpack];
    }

    private function method(string $path, int $lineStart, int $lineEnd, string $methodName): ClassMethod
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof ClassMethod || strcasecmp($node->name->toString(), $methodName) !== 0) {
                continue;
            }
            if ($node->getStartLine() === $lineStart && $node->getEndLine() === $lineEnd) {
                $matches[] = $node;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map method evidence to exactly one %s at %s:%d-%d; found %d candidate(s).',
                $methodName,
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        return $matches[0];
    }

    /**
     * @param list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}> $references
     * @param list<array{start_file_pos: int, end_file_pos: int, actual: string, line: int}> $replacementReferences
     */
    private function collectMethodVariables(
        string $path,
        Node $node,
        string $parameterName,
        string $replacementName,
        array &$references,
        array &$replacementReferences,
    ): void {
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            if ($this->subtreeContainsVariable($node, $parameterName) || $this->subtreeContainsVariable($node, $replacementName)) {
                throw new RuntimeException(sprintf(
                    'Parameter rename crosses a nested closure/arrow scope using $%s or $%s in %s:%d-%d.',
                    $parameterName,
                    $replacementName,
                    $path,
                    $node->getStartLine(),
                    $node->getEndLine(),
                ));
            }
            return;
        }
        if ($node instanceof Function_ || $node instanceof ClassLike) {
            return;
        }
        if ($node instanceof Variable && is_string($node->name)) {
            if ($node->name === $parameterName) {
                $references[] = $this->variablePosition($path, $node, $parameterName);
            } elseif ($node->name === $replacementName) {
                $replacementReferences[] = $this->variablePosition($path, $node, $replacementName);
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->collectMethodVariables($path, $child, $parameterName, $replacementName, $references, $replacementReferences);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->collectMethodVariables($path, $item, $parameterName, $replacementName, $references, $replacementReferences);
                }
            }
        }
    }

    private function subtreeContainsVariable(Node $node, string $name): bool
    {
        if ($node instanceof Variable && is_string($node->name) && $node->name === $name) {
            return true;
        }
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $child = $node->{$subNodeName};
            if ($child instanceof Node && $this->subtreeContainsVariable($child, $name)) {
                return true;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node && $this->subtreeContainsVariable($item, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string, line: int} */
    private function variablePosition(string $path, Variable $variable, string $expected): array
    {
        $start = $variable->getStartFilePos();
        $end = $variable->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose parameter byte positions for ' . $path . '.');
        }

        $actual = substr($this->source($path), $start, $end - $start + 1);
        if ($actual !== '$' . $expected) {
            throw new RuntimeException(sprintf(
                'Source parameter mismatch at %s:%d: expected "$%s", found "%s".',
                $path,
                $variable->getStartLine(),
                $expected,
                $actual,
            ));
        }

        return ['start_file_pos' => $start, 'end_file_pos' => $end, 'actual' => $actual, 'line' => $variable->getStartLine()];
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string, line: int} */
    private function identifierPosition(string $path, Identifier $identifier, string $expected): array
    {
        $start = $identifier->getStartFilePos();
        $end = $identifier->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose named-argument byte positions for ' . $path . '.');
        }

        $actual = substr($this->source($path), $start, $end - $start + 1);
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Source named-argument mismatch at %s:%d: expected "%s", found "%s".',
                $path,
                $identifier->getStartLine(),
                $expected,
                $actual,
            ));
        }

        return ['start_file_pos' => $start, 'end_file_pos' => $end, 'actual' => $actual, 'line' => $identifier->getStartLine()];
    }

    /** @return list<Node> */
    private function nodes(string $path): array
    {
        if (!isset($this->astByPath[$path])) {
            $this->astByPath[$path] = PhpCodeParser::getAstFromString($this->source($path));
        }

        $flat = [];
        foreach ($this->astByPath[$path] as $node) {
            $this->appendNode($flat, $node);
        }

        return $flat;
    }

    /** @param list<Node> $result */
    private function appendNode(array &$result, Node $node): void
    {
        $result[] = $node;
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->appendNode($result, $child);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->appendNode($result, $item);
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
                throw new RuntimeException('Cannot read parameter rename source file: ' . $path);
            }
            $this->sourceByPath[$path] = $source;
        }

        return $this->sourceByPath[$path];
    }
}
