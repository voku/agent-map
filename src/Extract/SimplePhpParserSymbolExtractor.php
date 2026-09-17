<?php

declare(strict_types=1);

namespace voku\AgentMap\Extract;

use Composer\Autoload\ClassLoader;
use Throwable;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\ParameterEntry;
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
use voku\SimplePhpParser\Parsers\Helper\ParserOptions;
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
            // astOnly() restricts parsing strictly to the source file text, avoiding
            // reflection-enrichment, parent autoloading, and inherited member explosion.
            $options = class_exists(ParserOptions::class) ? ParserOptions::astOnly() : null;
            $container = $this->withoutApplicationAutoloaders(static fn (): ParserContainer => PhpCodeParser::getPhpFiles(
                $code,
                options: $options,
            ));
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
     * Runs the parse with every non-Composer autoloader suspended.
     *
     * Extraction must stay a pure read of the source file. `PhpCodeParser` resolves
     * `{@inheritdoc}` parents through `class_exists($parent, true)`, so a host project whose
     * autoloader maps class names onto procedural legacy files turns "parse one file" into
     * "include and execute that parent file": page output, database connections, network calls.
     * The reflected parent also leaks into the container and would be indexed as a symbol of the
     * child file. Composer's own loader stays registered because the parser needs its own classes;
     * unresolved parents simply stay unresolved here, and PHPStan supplies the inherited types.
     *
     * @param callable(): ParserContainer $parse
     */
    private function withoutApplicationAutoloaders(callable $parse): ParserContainer
    {
        $registered = spl_autoload_functions();
        $suspended = [];
        foreach ($registered as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                continue;
            }
            spl_autoload_unregister($autoloader);
            $suspended[] = $autoloader;
        }

        if ($suspended === []) {
            return $parse();
        }

        try {
            return $parse();
        } finally {
            // Re-register everything in the original order: appending only the suspended
            // entries would silently move them behind Composer's loader.
            foreach ($registered as $autoloader) {
                spl_autoload_unregister($autoloader);
            }
            foreach ($registered as $autoloader) {
                spl_autoload_register($autoloader);
            }
        }
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
            parameters: $this->parameters($function->parameters),
            nativeReturnType: $function->getReturnType(),
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
            lineEnd: $method->endLine ?? $lineStart,
            static: $method->is_static ?? false,
            abstract: $method->is_abstract ?? false,
            final: $method->is_final ?? false,
            parameters: $this->parameters($method->parameters),
            nativeReturnType: $method->getReturnType(),
            attributes: $this->attributes($method->attributes),
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
     * @return list<ParameterEntry>
     */
    private function parameters(array $parameters): array
    {
        $entries = [];
        foreach ($parameters as $parameter) {
            $entries[] = new ParameterEntry(
                name: $parameter->name,
                nativeType: $parameter->getType(),
                byReference: $parameter->is_passed_by_ref ?? false,
                variadic: $parameter->is_vararg ?? false,
            );
        }

        return $entries;
    }

    /**
     * Threshold below which process spawning overhead outweighs concurrency benefits.
     */
    private const int PARALLEL_THRESHOLD = 5;

    /**
     * Maximum worker processes spawned regardless of detected CPU count.
     */
    private const int MAX_WORKERS = 16;

    /**
     * @param list<string> $files
     *
     * @return array<string, ExtractResult>
     */
    public function extractMany(array $files): array
    {
        if ($files === []) {
            return [];
        }

        if (count($files) < self::PARALLEL_THRESHOLD) {
            return $this->extractSequential($files);
        }

        if ($this->isPcntlParallelAvailable()) {
            $results = $this->runPcntlParallel($files);
            if ($results !== null) {
                return $results;
            }
        } elseif ($this->isProcOpenParallelAvailable()) {
            $results = $this->runProcOpenParallel($files);
            if ($results !== null) {
                return $results;
            }
        }

        return $this->extractSequential($files);
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, ExtractResult>
     */
    private function extractSequential(array $files): array
    {
        $results = [];
        foreach ($files as $file) {
            $results[$file] = $this->extract($file);
        }

        return $results;
    }

    private function isPcntlParallelAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair')
            && defined('STREAM_PF_UNIX');
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, ExtractResult>|null
     */
    private function runPcntlParallel(array $files): ?array
    {
        $workerCount = max(1, min(self::MAX_WORKERS, $this->detectCpuCount(), count($files)));
        $partitions = $this->partitionFiles($files, $workerCount);

        /** @var list<array{pid: int, socket: resource}> $spawned */
        $spawned = [];

        foreach ($partitions as $partition) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                foreach ($spawned as $w) {
                    fclose($w['socket']);
                }
                $this->reapAll($spawned);

                return null;
            }
            [$childSocket, $parentSocket] = $pair;

            $pid = pcntl_fork();
            if ($pid === -1) {
                fclose($childSocket);
                fclose($parentSocket);
                foreach ($spawned as $w) {
                    fclose($w['socket']);
                }
                $this->reapAll($spawned);

                return null;
            }

            if ($pid === 0) {
                fclose($parentSocket);
                $partitionResults = [];
                foreach ($partition as $file) {
                    $partitionResults[$file] = $this->extract($file);
                }
                $payload = serialize($partitionResults);
                fwrite($childSocket, $payload);
                fclose($childSocket);
                exit(0);
            }

            fclose($childSocket);
            $spawned[] = ['pid' => $pid, 'socket' => $parentSocket];
        }

        $allResults = [];
        foreach ($spawned as $worker) {
            $raw = '';
            while (!feof($worker['socket'])) {
                $block = fread($worker['socket'], 65_536);
                if ($block === false || $block === '') {
                    break;
                }
                $raw .= $block;
            }
            fclose($worker['socket']);

            if ($raw === '') {
                $this->reapAll($spawned);

                return null;
            }

            try {
                /** @var array<string, ExtractResult>|false $unserialized */
                $unserialized = unserialize($raw, ['allowed_classes' => [
                    ExtractResult::class,
                    SymbolEntry::class,
                    MethodEntry::class,
                    ParameterEntry::class,
                ]]);
                if (!is_array($unserialized)) {
                    $this->reapAll($spawned);

                    return null;
                }
                foreach ($unserialized as $file => $res) {
                    $allResults[$file] = $res;
                }
            } catch (Throwable) {
                $this->reapAll($spawned);

                return null;
            }
        }

        $this->reapAll($spawned);

        return $allResults;
    }

    /**
     * @param list<array{pid: int, socket: resource}> $spawned
     */
    private function reapAll(array $spawned): void
    {
        foreach ($spawned as $worker) {
            $status = 0;
            pcntl_waitpid($worker['pid'], $status);
        }
    }

    private function isProcOpenParallelAvailable(): bool
    {
        return function_exists('proc_open')
            && function_exists('proc_close')
            && $this->resolveAgentMapBin() !== null;
    }

    private function resolveAgentMapBin(): ?string
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $candidates = [
            (is_string($script) && str_ends_with($script, 'agent-map')) ? $script : null,
            realpath(__DIR__ . '/../../bin/agent-map'),
            realpath(getcwd() . '/vendor/bin/agent-map'),
            realpath(getcwd() . '/bin/agent-map'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, ExtractResult>|null
     */
    private function runProcOpenParallel(array $files): ?array
    {
        $bin = $this->resolveAgentMapBin();
        if ($bin === null) {
            return null;
        }

        $workerCount = max(1, min(self::MAX_WORKERS, $this->detectCpuCount(), count($files)));
        $partitions = $this->partitionFiles($files, $workerCount);

        /** @var list<array{proc: resource, pipes: array<int, resource>}> $processes */
        $processes = [];

        foreach ($partitions as $partition) {
            $cmd = [PHP_BINARY, $bin, 'extract-worker'];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                foreach ($processes as $p) {
                    fclose($p['pipes'][1]);
                    fclose($p['pipes'][2]);
                    proc_close($p['proc']);
                }

                return null;
            }

            try {
                fwrite($pipes[0], json_encode($partition, JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                return null;
            }
            fclose($pipes[0]);

            $processes[] = [
                'proc' => $proc,
                'pipes' => $pipes,
            ];
        }

        $allResults = [];
        foreach ($processes as $p) {
            $stdout = stream_get_contents($p['pipes'][1]);
            fclose($p['pipes'][1]);
            fclose($p['pipes'][2]);
            $status = proc_close($p['proc']);

            if ($status !== 0 || !is_string($stdout) || $stdout === '') {
                return null;
            }

            try {
                /** @var array<string, ExtractResult>|false $unserialized */
                $unserialized = unserialize($stdout, ['allowed_classes' => [
                    ExtractResult::class,
                    SymbolEntry::class,
                    MethodEntry::class,
                    ParameterEntry::class,
                ]]);
                if (!is_array($unserialized)) {
                    return null;
                }
                foreach ($unserialized as $file => $res) {
                    $allResults[$file] = $res;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return $allResults;
    }

    /**
     * @param list<string> $files
     *
     * @return list<list<string>>
     */
    private function partitionFiles(array $files, int $workers): array
    {
        $total = count($files);
        if ($total === 0) {
            return [];
        }

        $workers = max(1, min($workers, $total));
        $baseSize = intdiv($total, $workers);
        $remainder = $total % $workers;
        $partitions = [];
        $offset = 0;

        for ($i = 0; $i < $workers; ++$i) {
            $size = $baseSize + ($i < $remainder ? 1 : 0);
            if ($size > 0) {
                $partitions[] = array_slice($files, $offset, $size);
            }
            $offset += $size;
        }

        return $partitions;
    }

    private function detectCpuCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $info = @file_get_contents('/proc/cpuinfo');
            if (is_string($info)) {
                $count = substr_count($info, "\nprocessor\t:");
                if ($count > 0) {
                    return $count;
                }
            }
        }

        $nproc = @shell_exec('nproc 2>/dev/null');
        if (is_string($nproc)) {
            $count = (int) trim($nproc);
            if ($count > 0) {
                return $count;
            }
        }

        $sysctl = @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');
        if (is_string($sysctl)) {
            $count = (int) trim($sysctl);
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }
}
