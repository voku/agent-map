<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template\Smarty;

use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use voku\AgentMap\Reference\Template\SmartyDialect;

/**
 * Finds, in one PHP file, the calls that render Smarty templates and the calls that register PHP for templates.
 *
 * Per function body (closures are their own scope) it follows `assign()` calls per receiver expression and pairs them
 * with the later `display()` / `fetch()` on the same receiver; a template from `createTemplate()` also sees what its
 * parent receiver was assigned. Because `assign()`, `display()` and `fetch()` are common method names, a site is kept
 * only when the receiver is statically a Smarty object or the template name is one the Smarty dialect handles.
 *
 * Only statically clear types are recorded: `new Foo`, declared parameter and property types (including promoted
 * constructor parameters), and locals assigned from `new`. Everything else stays null; a later PHPStan-backed pass can
 * fill those in.
 *
 * Experimental: see docs/experiments/template-engine-evidence.md.
 */
final readonly class SmartyRenderSiteExtractor
{
    private const SMARTY_TYPES = ['smarty\\smarty', 'smarty\\template', 'smarty\\data', 'smarty'];
    private const PLUGIN_TYPES = ['function', 'block', 'compiler', 'modifier', 'modifiercompiler'];

    public function __construct(private SmartyDialect $dialect = new SmartyDialect())
    {
    }

    /** @return array{sites: list<SmartyRenderSite>, registrations: list<SmartyPluginRegistration>} */
    public function extract(string $path, string $source): array
    {
        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
            $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
        } catch (PhpParserError) {
            return ['sites' => [], 'registrations' => []];
        }

        $sites = [];
        $registrations = [];
        $finder = new NodeFinder();
        /** @var list<array{array<Node>, Stmt\ClassLike|null, Node\FunctionLike|null}> $scopes */
        $scopes = [[$statements, null, null]];
        /** @var list<Stmt\ClassLike> $classes */
        $classes = $finder->findInstanceOf($statements, Stmt\ClassLike::class);
        $owned = [];
        foreach ($classes as $class) {
            foreach ($class->getMethods() as $method) {
                $scopes[] = [$method->stmts ?? [], $class, $method];
                $owned[spl_object_id($method)] = true;
            }
        }
        /** @var list<Node\FunctionLike> $functions */
        $functions = $finder->find($statements, static fn (Node $node): bool => $node instanceof Stmt\Function_ || $node instanceof Expr\Closure);
        foreach ($functions as $function) {
            if (!isset($owned[spl_object_id($function)])) {
                $scopes[] = [$function->getStmts() ?? [], null, $function];
            }
        }

        foreach ($scopes as [$body, $class, $function]) {
            [$scopeSites, $scopeRegistrations] = $this->scope($path, $source, $body, $class, $function);
            array_push($sites, ...$scopeSites);
            array_push($registrations, ...$scopeRegistrations);
        }
        usort($sites, static fn (SmartyRenderSite $a, SmartyRenderSite $b): int => $a->line <=> $b->line);
        usort($registrations, static fn (SmartyPluginRegistration $a, SmartyPluginRegistration $b): int => $a->line <=> $b->line);

        return ['sites' => $sites, 'registrations' => $registrations];
    }

    /**
     * @param array<Node> $body
     * @return array{list<SmartyRenderSite>, list<SmartyPluginRegistration>}
     */
    private function scope(string $path, string $source, array $body, ?Stmt\ClassLike $class, ?Node\FunctionLike $function): array
    {
        $types = $function === null ? [] : $this->parameterTypes($function);
        $properties = $class === null ? [] : $this->propertyTypes($class);
        $owner = $class?->namespacedName?->toString();

        /** @var array<string, array<string, array{expression: string, type: string|null}>> $assigned receiver => variables */
        $assigned = [];
        /** @var array<string, array{template: string|null, parent: string}> $created receiver => createTemplate() origin */
        $created = [];
        $sites = [];
        $registrations = [];

        foreach ($this->nodesInScope($body) as $node) {
            if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
                if ($node->expr instanceof Expr\New_ && $node->expr->class instanceof Node\Name) {
                    $types[$node->var->name] = $node->expr->class->toString();
                } elseif ($node->expr instanceof Expr\MethodCall && $this->methodName($node->expr) === 'createtemplate') {
                    $template = $this->templateArgument($node->expr);
                    $created['$' . $node->var->name] = ['template' => $template, 'parent' => $this->text($node->expr->var, $source)];
                    $types[$node->var->name] = $this->receiverProven($node->expr->var, $types, $properties) ? 'Smarty\\Template' : ($types[$node->var->name] ?? null);
                }
                continue;
            }
            if (!$node instanceof Expr\MethodCall) {
                continue;
            }
            $method = $this->methodName($node);
            if ($method === null) {
                continue;
            }
            $receiver = $this->text($node->var, $source);
            $proven = $this->receiverProven($node->var, $types, $properties);

            if ($method === 'assign') {
                foreach ($this->assignments($node, $source, $types, $properties) as $name => $variable) {
                    $assigned[$receiver][$name] = $variable;
                }
            } elseif ($method === 'display' || $method === 'fetch') {
                $template = $node->getArgs() === [] ? ($created[$receiver]['template'] ?? null) : $this->templateArgument($node);
                $named = $template !== null && $this->dialect->handles($template);
                if (!$proven && !$named) {
                    continue; // some other object's display()/fetch()
                }
                $variables = $assigned[$receiver] ?? [];
                if (isset($created[$receiver])) {
                    $variables += $assigned[$created[$receiver]['parent']] ?? [];
                }
                ksort($variables, SORT_STRING);
                $sites[] = new SmartyRenderSite($path, $node->getStartLine(), $method, $template, $variables, $proven);
            } elseif ($method === 'registerplugin') {
                $type = $this->stringArgument($node, 0);
                if ($type !== null && in_array(strtolower($type), self::PLUGIN_TYPES, true)) {
                    $registrations[] = new SmartyPluginRegistration(
                        SmartyPluginRegistration::KIND_PLUGIN,
                        $path,
                        $node->getStartLine(),
                        strtolower($type),
                        $this->stringArgument($node, 1),
                        $this->callback($node->getArgs()[2]->value ?? null, $owner),
                    );
                }
            } elseif ($method === 'registerclass' && $proven) {
                $registrations[] = new SmartyPluginRegistration(
                    SmartyPluginRegistration::KIND_CLASS,
                    $path,
                    $node->getStartLine(),
                    null,
                    $this->stringArgument($node, 0),
                    $this->className($node->getArgs()[1]->value ?? null),
                );
            } elseif ($method === 'addextension' && $proven) {
                $argument = $node->getArgs()[0]->value ?? null;
                $registrations[] = new SmartyPluginRegistration(
                    SmartyPluginRegistration::KIND_EXTENSION,
                    $path,
                    $node->getStartLine(),
                    null,
                    null,
                    $argument instanceof Expr\New_ && $argument->class instanceof Node\Name ? $argument->class->toString() : null,
                );
            }
        }

        return [$sites, $registrations];
    }

    /**
     * Nodes of one scope in source order, without descending into nested functions, closures or classes.
     *
     * @param array<Node> $nodes
     * @return list<Node>
     */
    private function nodesInScope(array $nodes): array
    {
        $found = [];
        $walk = static function (Node $node) use (&$walk, &$found): void {
            if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                return;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                foreach (is_array($child) ? $child : [$child] as $item) {
                    if ($item instanceof Node) {
                        $walk($item);
                    }
                }
            }
            // Post-order: `$t->assign('a', 1)->display('x.tpl')` assigns before it displays.
            $found[] = $node;
        };
        foreach ($nodes as $node) {
            $walk($node);
        }

        return $found;
    }

    /**
     * @param array<string, string|null> $types
     * @param array<string, string> $properties
     * @return array<string, array{expression: string, type: string|null}>
     */
    private function assignments(Expr\MethodCall $call, string $source, array $types, array $properties): array
    {
        $arguments = $call->getArgs();
        $first = $arguments[0]->value ?? null;
        if ($first instanceof String_ && isset($arguments[1])) {
            return [$first->value => $this->variable($arguments[1]->value, $source, $types, $properties)];
        }
        $variables = [];
        if ($first instanceof Expr\Array_) {
            foreach ($first->items as $item) {
                if ($item->key instanceof String_) {
                    $variables[$item->key->value] = $this->variable($item->value, $source, $types, $properties);
                }
            }
        }

        return $variables;
    }

    /**
     * @param array<string, string|null> $types
     * @param array<string, string> $properties
     * @return array{expression: string, type: string|null}
     */
    private function variable(Expr $expression, string $source, array $types, array $properties): array
    {
        return ['expression' => $this->text($expression, $source), 'type' => $this->typeOf($expression, $types, $properties)];
    }

    /**
     * @param array<string, string|null> $types
     * @param array<string, string> $properties
     */
    private function typeOf(Expr $expression, array $types, array $properties): ?string
    {
        return match (true) {
            $expression instanceof Expr\New_ && $expression->class instanceof Node\Name => $expression->class->toString(),
            $expression instanceof Expr\Variable && is_string($expression->name) => $types[$expression->name] ?? null,
            $expression instanceof Expr\PropertyFetch
                && $expression->var instanceof Expr\Variable && $expression->var->name === 'this'
                && $expression->name instanceof Node\Identifier => $properties[$expression->name->toString()] ?? null,
            default => null,
        };
    }

    /**
     * @param array<string, string|null> $types
     * @param array<string, string> $properties
     */
    private function receiverProven(Expr $receiver, array $types, array $properties): bool
    {
        $type = $this->typeOf($receiver, $types, $properties);

        return $type !== null && in_array(strtolower($type), self::SMARTY_TYPES, true);
    }

    /** @return array<string, string|null> parameter name => class type */
    private function parameterTypes(Node\FunctionLike $function): array
    {
        $types = [];
        foreach ($function->getParams() as $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $this->declaredClass($parameter->type);
            }
        }

        return $types;
    }

    /** @return array<string, string> property name => class type (declared properties and promoted parameters) */
    private function propertyTypes(Stmt\ClassLike $class): array
    {
        $types = [];
        foreach ($class->getProperties() as $property) {
            $type = $this->declaredClass($property->type);
            foreach ($property->props as $item) {
                if ($type !== null) {
                    $types[$item->name->toString()] = $type;
                }
            }
        }
        $constructor = $class->getMethod('__construct');
        foreach ($constructor?->getParams() ?? [] as $parameter) {
            $type = $this->declaredClass($parameter->type);
            if ($parameter->flags !== 0 && $type !== null && $parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $type;
            }
        }

        return $types;
    }

    private function declaredClass(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        return $type instanceof Node\Name ? $type->toString() : null;
    }

    private function methodName(Expr\MethodCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : null;
    }

    private function templateArgument(Expr\MethodCall $call): ?string
    {
        $name = $this->stringArgument($call, 0);

        return $name === null ? null : (string) preg_replace('/\Afile:/', '', $name);
    }

    private function stringArgument(Expr\MethodCall $call, int $position): ?string
    {
        $value = $call->getArgs()[$position]->value ?? null;

        return $value instanceof String_ ? $value->value : null;
    }

    private function className(?Expr $expression): ?string
    {
        return match (true) {
            $expression instanceof Expr\ClassConstFetch && $expression->class instanceof Node\Name
                && $expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'class' => $expression->class->toString(),
            $expression instanceof String_ => ltrim($expression->value, '\\'),
            default => null,
        };
    }

    /** `'fn'`, `'A::b'`, `[A::class, 'b']`, `['A', 'b']`, `[$this, 'b']`, `A::b(...)`, `$this->b(...)`, `fn(...)`. */
    private function callback(?Expr $callback, ?string $owner): ?string
    {
        if ($callback instanceof String_) {
            return ltrim($callback->value, '\\');
        }
        if ($callback instanceof Expr\Array_ && count($callback->items) === 2) {
            $target = $callback->items[0]->value;
            $method = $callback->items[1]->value;
            if (!$method instanceof String_) {
                return null;
            }
            $class = $target instanceof Expr\Variable && $target->name === 'this' ? $owner : $this->className($target);

            return $class === null ? null : $class . '::' . $method->value;
        }
        if ($callback instanceof Expr\StaticCall && $callback->isFirstClassCallable() && $callback->class instanceof Node\Name && $callback->name instanceof Node\Identifier) {
            return $callback->class->toString() . '::' . $callback->name->toString();
        }
        if ($callback instanceof Expr\MethodCall && $callback->isFirstClassCallable() && $callback->var instanceof Expr\Variable
            && $callback->var->name === 'this' && $owner !== null && $callback->name instanceof Node\Identifier) {
            return $owner . '::' . $callback->name->toString();
        }
        if ($callback instanceof Expr\FuncCall && $callback->isFirstClassCallable() && $callback->name instanceof Node\Name) {
            return $callback->name->toString();
        }

        return null;
    }

    private function text(Node $node, string $source): string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return $start >= 0 && $end >= $start ? substr($source, $start, $end - $start + 1) : '';
    }
}
