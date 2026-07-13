<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

use Throwable;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\SimplePhpParser\Model\BasePHPClass;
use voku\SimplePhpParser\Model\PHPAttribute;
use voku\SimplePhpParser\Model\PHPClass;
use voku\SimplePhpParser\Model\PHPEnum;
use voku\SimplePhpParser\Model\PHPFunction;
use voku\SimplePhpParser\Model\PHPInterface;
use voku\SimplePhpParser\Model\PHPMethod;
use voku\SimplePhpParser\Model\PHPParameter;
use voku\SimplePhpParser\Parsers\Helper\ParserContainer;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/**
 * Extracts rich symbols (extends/implements, method + function signatures,
 * PHP 8 attributes) via `voku/simple-php-code-parser` (nikic/php-parser
 * under the hood).
 */
final readonly class SimplePhpParserSymbolExtractor implements SymbolExtractor
{
    public function extract(string $file): ExtractResult
    {
        $code = file_get_contents($file);
        if (!is_string($code)) {
            return new ExtractResult($file, false, [], 'Unable to read PHP file: ' . $file);
        }

        try {
            // Pass the already-read $code rather than $file: getPhpFiles()
            // would otherwise re-read the file itself, doubling disk I/O.
            $container = PhpCodeParser::getPhpFiles($code);
        } catch (Throwable $e) {
            return new ExtractResult($file, false, [], $e->getMessage());
        }

        $errors = $container->getParseErrors();
        if ($errors !== []) {
            return new ExtractResult($file, false, [], implode("\n", $errors));
        }

        return new ExtractResult($file, true, $this->symbols($container));
    }

    /**
     * @return list<SymbolEntry>
     */
    private function symbols(ParserContainer $container): array
    {
        $classModels = [];
        foreach ($container->getClasses() as $class) {
            if ($class->is_anonymous === true) {
                continue;
            }

            $classModels[] = ['kind' => 'class', 'model' => $class];
        }
        foreach ($container->getInterfaces() as $interface) {
            $classModels[] = ['kind' => 'interface', 'model' => $interface];
        }
        foreach ($container->getTraits() as $trait) {
            $classModels[] = ['kind' => 'trait', 'model' => $trait];
        }
        foreach ($container->getEnums() as $enum) {
            $classModels[] = ['kind' => 'enum', 'model' => $enum];
        }

        usort($classModels, static fn (array $left, array $right): int => ($left['model']->line ?? 0) <=> ($right['model']->line ?? 0));

        $symbols = [];
        foreach ($classModels as $entry) {
            $symbols[] = $this->classSymbol($entry['kind'], $entry['model']);
        }

        $functions = array_values($container->getFunctions());
        usort($functions, static fn (PHPFunction $left, PHPFunction $right): int => ($left->line ?? 0) <=> ($right->line ?? 0));

        foreach ($functions as $function) {
            $symbols[] = $this->functionSymbol($function);
        }

        return $symbols;
    }

    private function classSymbol(string $kind, BasePHPClass $class): SymbolEntry
    {
        $fqn = ltrim($class->name, '\\');
        $name = str_contains($fqn, '\\') ? substr($fqn, (int) strrpos($fqn, '\\') + 1) : $fqn;
        $lineStart = $class->line ?? 0;

        $extends = [];
        $implements = [];
        if ($class instanceof PHPClass) {
            $extends = $class->parentClass !== null ? [$class->parentClass] : [];
            $implements = array_values($class->interfaces);
        } elseif ($class instanceof PHPInterface) {
            $extends = array_values($class->parentInterfaces);
        } elseif ($class instanceof PHPEnum) {
            $implements = array_values($class->interfaces);
        }

        $methodModels = array_values($class->methods);
        usort($methodModels, static fn (PHPMethod $left, PHPMethod $right): int => ($left->line ?? 0) <=> ($right->line ?? 0));

        return new SymbolEntry(
            kind: $kind,
            name: $name,
            fqn: $fqn,
            lineStart: $lineStart,
            lineEnd: $class->endLine ?? $lineStart,
            methods: array_map($this->methodEntry(...), $methodModels),
            extends: $extends,
            implements: $implements,
            attributes: $this->attributes($class->attributes),
            uses: array_values($class->traitUses),
        );
    }

    private function functionSymbol(PHPFunction $function): SymbolEntry
    {
        $fqn = ltrim($function->name, '\\');
        $name = str_contains($fqn, '\\') ? substr($fqn, (int) strrpos($fqn, '\\') + 1) : $fqn;
        $lineStart = $function->line ?? 0;

        return new SymbolEntry(
            kind: 'function',
            name: $name,
            fqn: $fqn,
            lineStart: $lineStart,
            lineEnd: $function->endLine ?? $lineStart,
            params: $this->parameters($function->parameters),
            returnType: $function->getReturnType(),
            attributes: $this->attributes($function->attributes),
        );
    }

    private function methodEntry(PHPMethod $method): MethodEntry
    {
        $lineStart = $method->line ?? 0;

        return new MethodEntry(
            name: $method->name,
            visibility: $method->access !== '' ? $method->access : 'public',
            lineStart: $lineStart,
            static: $method->is_static ?? false,
            params: $this->parameters($method->parameters),
            returnType: $method->getReturnType(),
            attributes: $this->attributes($method->attributes),
            lineEnd: $method->endLine ?? $lineStart,
        );
    }

    /**
     * @param array<PHPAttribute> $attributes
     *
     * @return list<string>
     */
    private function attributes(array $attributes): array
    {
        return array_values(array_map($this->renderAttribute(...), $attributes));
    }

    /**
     * Renders one attribute as `Name(arg, key: arg, ...)`.
     *
     * Known upstream limitation: PhpCodeParser's argument-value resolver
     * (voku\SimplePhpParser\Parsers\Helper\Utils::getPhpParserValueFromNode)
     * behaves differently when reached through an Arg node than when called
     * directly on an expression. For enum-case/class-const arguments
     * (`#[Rule(ArchitectureRules::Foo)]`) it returns only the bare case name
     * as a string ('Foo'), indistinguishable here from a real string literal
     * argument — the enclosing enum/class is not recoverable at this layer.
     */
    private function renderAttribute(PHPAttribute $attribute): string
    {
        $args = [];
        foreach ($attribute->arguments as $key => $value) {
            $rendered = $this->renderAttributeValue($value);
            $args[] = is_string($key) ? $key . ': ' . $rendered : $rendered;
        }

        return $attribute->name . ($args !== [] ? '(' . implode(', ', $args) . ')' : '');
    }

    private function renderAttributeValue(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->renderAttributeValue(...), $value)) . ']';
        }

        // Array-literal attribute arguments resolved via the AST path (no
        // reflection) come back as an unresolved php-parser node rather than
        // a real PHP array — voku/simple-php-code-parser's
        // getPhpParserValueFromNode() only unwraps Array_ nodes passed
        // directly, not ones reached through an Arg wrapper. Render as an
        // explicit placeholder instead of guessing at the contents.
        return '...';
    }

    /**
     * @param array<string, PHPParameter> $parameters
     *
     * @return list<string>
     */
    private function parameters(array $parameters): array
    {
        $formatted = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $marker = ($parameter->is_passed_by_ref ? '&' : '') . ($parameter->is_vararg ? '...' : '');
            $formatted[] = trim(($type !== null ? $type . ' ' : '') . $marker . '$' . $parameter->name);
        }

        return $formatted;
    }
}
