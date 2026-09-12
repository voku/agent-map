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

    /** @var array<string, string> */
    private array $classConstants = [];

    /** @var array<string, Expr> */
    private array $classMethodReturns = [];

    /** @var array<string, string> */
    private array $localVars = [];

    /**
     * @param list<string> $templateNames
     */
    public function __construct(
        private readonly int $lineStart,
        private readonly int $lineEnd,
        private readonly array $templateNames,
    ) {
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->collectDeclarations($nodes);

        return null;
    }

    /**
     * @param array<Node> $nodes
     */
    private function collectDeclarations(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\ClassLike) {
                foreach ($node->getConstants() as $const) {
                    foreach ($const->consts as $c) {
                        if ($c->value instanceof Scalar\String_) {
                            $this->classConstants[$c->name->toString()] = $c->value->value;
                        }
                    }
                }
                foreach ($node->getMethods() as $method) {
                    $methodName = $method->name->toString();
                    foreach ($method->stmts ?? [] as $stmt) {
                        if ($stmt instanceof Stmt\Return_ && $stmt->expr !== null) {
                            $this->classMethodReturns[$methodName] = $stmt->expr;
                        }
                    }
                }
            } elseif ($node instanceof Stmt\Namespace_) {
                $this->collectDeclarations($node->stmts);
            }
        }
    }

    public function enterNode(Node $node): null
    {
        $line = $node->getStartLine();
        if ($line < $this->lineStart || $line > $this->lineEnd) {
            return null;
        }

        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $resolved = $this->resolveStringValue($node->expr);
            if ($resolved !== null) {
                $this->localVars[$node->var->name] = $resolved;
            }
        } elseif ($node instanceof Stmt\Return_ && $node->expr !== null) {
            $resolved = $this->resolveStringValue($node->expr);
            if ($resolved !== null && $this->looksLikeTemplatePath($resolved)) {
                $this->addTemplateHint($resolved, $line, 'return');
            }
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
        $methodName = $node->name->toString();
        $this->calls[] = new CallHint('static', $class . '::' . $methodName, $line);
        $this->maybeTemplate($methodName, $node->args, $line);
    }

    private function onNew(Expr\New_ $node, int $line): void
    {
        if ($node->class instanceof Node\Name) {
            $className = $this->shortName($node->class->toString());
            $this->calls[] = new CallHint('constructor', $className, $line);
            $this->maybeTemplateFromArgs($node->args, $line, $className);

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
        $short = $this->shortName($calledName);
        $isRecognized = in_array($short, $this->templateNames, true);

        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $argName = $arg->name?->toString();
            $isTemplateArg = $argName !== null && str_contains(strtolower($argName), 'template');
            $resolved = $this->resolveStringValue($arg->value);
            if ($resolved !== null && ($isTemplateArg || $this->looksLikeTemplatePath($resolved))) {
                $this->addTemplateHint($resolved, $line, $short);

                return;
            }
        }

        if (!$isRecognized) {
            return;
        }

        $first = $args[0] ?? null;
        if (!$first instanceof Node\Arg) {
            return;
        }

        $resolved = $this->resolveStringValue($first->value);
        if ($resolved !== null) {
            $this->addTemplateHint($resolved, $line, $short);

            return;
        }

        $this->templates[] = new TemplateHint('<dynamic>', $line);
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function maybeTemplateFromArgs(array $args, int $line, ?string $caller = null): void
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $argName = $arg->name?->toString();
            $isTemplateArg = $argName !== null && str_contains(strtolower($argName), 'template');
            $resolved = $this->resolveStringValue($arg->value);
            if ($resolved !== null && ($isTemplateArg || $this->looksLikeTemplatePath($resolved))) {
                $this->addTemplateHint($resolved, $line, $caller);

                return;
            }
        }
    }

    private function addTemplateHint(string $val, int $line, ?string $caller = null): void
    {
        $engine = $this->detectTemplateEngine($val);
        $kind = 'file';

        if (str_starts_with($val, 'string:') || str_contains($val, '<fieldset') || str_contains($val, '<table') || str_contains($val, '<div')) {
            $kind = 'inline';
            $cleaned = trim((string) preg_replace('/\s+/', ' ', $val));
            $path = 'inline: ' . (strlen($cleaned) > 50 ? substr($cleaned, 0, 47) . '...' : $cleaned);
        } else {
            $path = $val;
        }

        foreach ($this->templates as $existing) {
            if ($existing->path === $path && $existing->line === $line) {
                return;
            }
        }

        $this->templates[] = new TemplateHint(
            path: $path,
            line: $line,
            kind: $kind,
            engine: $engine,
            caller: $caller,
        );
    }

    private function looksLikeTemplatePath(string $val): bool
    {
        if (str_starts_with($val, 'string:')) {
            return true;
        }

        return (bool) preg_match('~\.(tpl|twig|blade\.php|html\.twig|smarty)$~i', $val);
    }

    private function detectTemplateEngine(string $path): ?string
    {
        if (str_ends_with($path, '.tpl') || str_starts_with($path, 'string:')) {
            return 'smarty';
        }
        if (str_ends_with($path, '.twig') || str_ends_with($path, '.html.twig')) {
            return 'twig';
        }
        if (str_ends_with($path, '.blade.php')) {
            return 'blade';
        }

        return null;
    }

    private function resolveStringValue(Expr $expr): ?string
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->resolveStringValue($expr->left);
            $right = $this->resolveStringValue($expr->right);
            if ($left !== null && $right !== null) {
                return $left . $right;
            }
            if ($left !== null && (str_starts_with($left, 'string:') || $this->looksLikeTemplatePath($left))) {
                return $left;
            }
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name) && isset($this->localVars[$expr->name])) {
            return $this->localVars[$expr->name];
        }

        if ($expr instanceof Expr\ClassConstFetch && $expr->name instanceof Node\Identifier) {
            $constName = $expr->name->toString();
            if (isset($this->classConstants[$constName])) {
                return $this->classConstants[$constName];
            }
        }

        if (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall)
            && $this->isThisCall($expr)
            && $expr->name instanceof Node\Identifier
        ) {
            $methodName = $expr->name->toString();
            if (isset($this->classMethodReturns[$methodName])) {
                return $this->resolveStringValue($this->classMethodReturns[$methodName]);
            }
        }

        return null;
    }

    private function isThisCall(Expr\MethodCall|Expr\NullsafeMethodCall $expr): bool
    {
        return $expr->var instanceof Expr\Variable && $expr->var->name === 'this';
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
