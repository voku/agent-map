<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps one resolved method parameter and its named call arguments to exact current-source tokens. */
final class ParameterNameLocator
{
    /** @var array<string, array<int, Node>> */
    private array $astByPath = [];

    /** @var array<string, string> */
    private array $sourceByPath = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string} */
    public function declaration(
        string $path,
        int $lineStart,
        int $lineEnd,
        string $methodName,
        string $parameterName,
        int $parameterIndex,
    ): array {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof ClassMethod || strcasecmp($node->name->toString(), $methodName) !== 0) {
                continue;
            }
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }

            $parameter = $node->params[$parameterIndex] ?? null;
            if (!$parameter instanceof Param || !$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                continue;
            }
            if ($parameter->var->name !== $parameterName) {
                continue;
            }

            $matches[] = $this->variablePosition($path, $parameter->var, $parameterName);
        }

        return $this->one($matches, $path, $lineStart, $lineEnd, 'parameter declaration', '$' . $parameterName);
    }

    /**
     * @return array{
     *   named: list<array{start_file_pos: int, end_file_pos: int, actual: string}>,
     *   replacement_named: list<array{start_file_pos: int, end_file_pos: int, actual: string}>,
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

    /**
     * @return array{
     *   named: list<array{start_file_pos: int, end_file_pos: int, actual: string}>,
     *   replacement_named: list<array{start_file_pos: int, end_file_pos: int, actual: string}>,
     *   has_unpack: bool
     * }
     */
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

    /**
     * @param list<array{start_file_pos: int, end_file_pos: int, actual: string}> $matches
     * @return array{start_file_pos: int, end_file_pos: int, actual: string}
     */
    private function one(array $matches, string $path, int $lineStart, int $lineEnd, string $kind, string $expected): array
    {
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map %s evidence to exactly one "%s" token at %s:%d-%d; found %d candidate(s).',
                $kind,
                $expected,
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        return $matches[0];
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string} */
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

        return ['start_file_pos' => $start, 'end_file_pos' => $end, 'actual' => $actual];
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string} */
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

        return ['start_file_pos' => $start, 'end_file_pos' => $end, 'actual' => $actual];
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
