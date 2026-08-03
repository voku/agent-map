<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects call/table/template hints from nodes inside [lineStart, lineEnd].
 *
 * Discovery is purely syntactic: a `$repository->store()` call is first
 * reported as a method call on `$repository`. ScopeInspector may then enrich
 * that hint from the already-built map; this visitor never runs type inference.
 */
final class ScopeAstVisitor extends NodeVisitorAbstract
{
    /**
     * Ordered longest-alternative-first so `DELETE FROM x` is consumed as one
     * match instead of also re-matching the trailing bare `FROM x`.
     */
    private const SQL_KEYWORD_PATTERN = '(?:(?P<insert>INSERT\s+INTO)|(?P<delete>DELETE\s+FROM)|(?P<replace>REPLACE\s+INTO)|(?P<update>UPDATE)|(?P<from>FROM)|(?P<join>JOIN))';

    private const SQL_ACTION_BY_GROUP = [
        'insert' => 'INSERT',
        'delete' => 'DELETE',
        'replace' => 'REPLACE',
        'update' => 'UPDATE',
        'from' => 'SELECT',
        'join' => 'SELECT',
    ];

    /** @var list<CallHint> */
    public array $calls = [];

    /** @var list<TableHint> */
    public array $tables = [];

    /** @var list<TemplateHint> */
    public array $templates = [];

    /**
     * @param list<string> $templateNames
     */
    public function __construct(
        private readonly int $lineStart,
        private readonly int $lineEnd,
        private readonly array $templateNames,
    ) {
    }

    public function enterNode(Node $node): null
    {
        $line = $node->getStartLine();
        if ($line < $this->lineStart || $line > $this->lineEnd) {
            return null;
        }

        if ($node instanceof Expr\FuncCall) {
            $this->onFuncCall($node, $line);
        } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $this->onMethodCall($node, $line);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->onStaticCall($node, $line);
        } elseif ($node instanceof Expr\New_) {
            $this->onNew($node, $line);
        } elseif ($node instanceof Scalar\String_) {
            $this->onString($node, $line);
        } elseif ($node instanceof Expr\BinaryOp\Concat) {
            $this->onConcat($node, $line);
        }

        return null;
    }

    private function onFuncCall(Expr\FuncCall $node, int $line): void
    {
        if (!$node->name instanceof Node\Name) {
            $this->calls[] = new CallHint('dynamic_function', 'dynamic function call', $line);

            return;
        }

        $name = $node->name->toString();
        $this->calls[] = new CallHint('function', $this->shortName($name), $line);
        $this->maybeTemplate($name, $node->args, $line);
    }

    private function onMethodCall(Expr\MethodCall|Expr\NullsafeMethodCall $node, int $line): void
    {
        if (!$node->name instanceof Node\Identifier) {
            $this->calls[] = new CallHint('dynamic_method', 'dynamic method call', $line);

            return;
        }

        $label = $this->varLabel($node->var) . '->' . $node->name->toString();
        $this->calls[] = new CallHint('method', $label, $line);
        $this->maybeTemplate($node->name->toString(), $node->args, $line);
    }

    private function onStaticCall(Expr\StaticCall $node, int $line): void
    {
        if (!$node->name instanceof Node\Identifier) {
            $this->calls[] = new CallHint('dynamic_method', 'dynamic method call', $line);

            return;
        }

        $class = $node->class instanceof Node\Name ? $this->shortName($node->class->toString()) : '...';
        $this->calls[] = new CallHint('static', $class . '::' . $node->name->toString(), $line);
    }

    private function onNew(Expr\New_ $node, int $line): void
    {
        if ($node->class instanceof Node\Name) {
            $this->calls[] = new CallHint('constructor', $this->shortName($node->class->toString()), $line);

            return;
        }

        if ($node->class instanceof Stmt\Class_) {
            // Anonymous class body isn't a navigable call target.
            return;
        }

        $this->calls[] = new CallHint('dynamic_constructor', 'dynamic constructor call', $line);
    }

    private function onString(Scalar\String_ $node, int $line): void
    {
        if (!preg_match_all('~\b' . self::SQL_KEYWORD_PATTERN . '\s+`?(?P<table>[a-zA-Z_][a-zA-Z0-9_]*)`?~i', $node->value, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $action = $this->sqlActionFromMatch($match);
            if ($action === null) {
                continue;
            }

            $this->tables[] = new TableHint($match['table'], $action, $line);
        }
    }

    private function onConcat(Expr\BinaryOp\Concat $node, int $line): void
    {
        if (!$node->left instanceof Scalar\String_ || $node->right instanceof Scalar\String_) {
            return;
        }

        if (!preg_match('~' . self::SQL_KEYWORD_PATTERN . '\s*$~i', rtrim($node->left->value), $match)) {
            return;
        }

        $action = $this->sqlActionFromMatch($match);
        if ($action === null) {
            return;
        }

        $this->tables[] = new TableHint('<dynamic table>', $action, $line);
    }

    /**
     * @param array<int|string, string> $match
     */
    private function sqlActionFromMatch(array $match): ?string
    {
        foreach (self::SQL_ACTION_BY_GROUP as $group => $action) {
            if (($match[$group] ?? '') !== '') {
                return $action;
            }
        }

        return null;
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function maybeTemplate(string $calledName, array $args, int $line): void
    {
        if (!in_array($this->shortName($calledName), $this->templateNames, true)) {
            return;
        }

        $first = $args[0] ?? null;
        if (!$first instanceof Node\Arg) {
            return;
        }

        if ($first->value instanceof Scalar\String_) {
            $this->templates[] = new TemplateHint($first->value->value, $line);

            return;
        }

        $this->templates[] = new TemplateHint('<dynamic>', $line);
    }

    private function varLabel(Expr $var): string
    {
        if ($var instanceof Expr\Variable && is_string($var->name)) {
            return '$' . $var->name;
        }

        return '...';
    }

    private function shortName(string $fqn): string
    {
        return str_contains($fqn, '\\') ? substr($fqn, (int) strrpos($fqn, '\\') + 1) : $fqn;
    }
}
