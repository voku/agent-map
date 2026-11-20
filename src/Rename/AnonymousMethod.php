<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/**
 * A method of an anonymous class, as PHPStan names it (`method:AnonymousClass<md5>::name`). The class has no symbol in
 * the structural index; rename planners reach it through its `overrides` relation, which carries file and lines.
 */
final readonly class AnonymousMethod
{
    public static function isId(string $methodId): bool
    {
        return preg_match('/^method:AnonymousClass[0-9a-f]{32}::[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $methodId) === 1;
    }

    /** `AnonymousClass<md5>`: the receiver-type and `class:` spelling of the owner. */
    public static function ownerFqn(string $methodId): string
    {
        return substr($methodId, strlen('method:'), (int) strpos($methodId, '::') - strlen('method:'));
    }

    /**
     * Anonymous classes that directly declare `$methodName` at exactly these lines. A caller requires exactly one;
     * an outer class whose own method spans the same lines does not match because the name differs.
     *
     * @return list<array{class: Class_, method: ClassMethod}>
     */
    public static function owners(string $root, string $path, string $methodName, int $lineStart, int $lineEnd): array
    {
        $source = @file_get_contents(rtrim($root, '/') . '/' . $path);
        if (!is_string($source)) {
            return [];
        }
        $owners = [];
        foreach ((new NodeFinder())->find(
            PhpCodeParser::getAstFromString($source),
            static fn (Node $node): bool => $node instanceof Class_ && $node->name === null,
        ) as $class) {
            if (!$class instanceof Class_) {
                continue;
            }
            foreach ($class->getMethods() as $method) {
                if (strcasecmp($method->name->toString(), $methodName) === 0
                    && $method->getStartLine() === $lineStart
                    && $method->getEndLine() === $lineEnd) {
                    $owners[] = ['class' => $class, 'method' => $method];
                }
            }
        }

        return $owners;
    }
}
