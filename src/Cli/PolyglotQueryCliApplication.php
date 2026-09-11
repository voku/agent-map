<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use voku\AgentMap\Evidence\DefinitionEvidence;
use voku\AgentMap\Evidence\ScipDefinitionProvider;
use voku\AgentMap\MapArtifactPaths;

final readonly class PolyglotQueryCliApplication
{
    public function __construct(
        private ?MapArtifactPaths $artifacts = null,
        private ?string $defaultRoot = null,
    ) {
    }

    /** @param list<string> $argv */
    public function supports(array $argv): bool
    {
        if (($argv[1] ?? null) !== 'query') {
            return false;
        }

        try {
            $options = $this->options($argv);
        } catch (Throwable) {
            return false;
        }

        if (is_file($options->index)) {
            return false;
        }

        $query = (string) $options->argument;
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $query) !== 1) {
            return false;
        }

        $root = rtrim($options->root, '/\\');
        $supportedProject = is_file($root . '/package.json')
            || is_file($root . '/tsconfig.json')
            || is_file($root . '/jsconfig.json')
            || is_file($root . '/pyproject.toml')
            || is_file($root . '/setup.py')
            || is_file($root . '/setup.cfg');
        if (!$supportedProject) {
            return false;
        }

        // A missing PHP map is not evidence that PHP is absent. In a mixed PHP+JS
        // repository, letting SCIP answer before the PHP owner is built can turn a
        // real PHP symbol into semantic `not_found`. This bounded scan stops on the
        // first PHP source and prunes dependency/build trees rather than building a
        // second language index merely to decide who owns the question.
        if (is_file($root . '/composer.json') || $this->containsPhpSource($root)) {
            return false;
        }

        return true;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            $options = $this->options($argv);
            $query = (string) $options->argument;
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $query) !== 1) {
                $evidence = DefinitionEvidence::unavailable(
                    'No PHP map exists and the first SCIP fallback accepts exact identifier-shaped definition queries only.',
                );
            } else {
                $evidence = (new ScipDefinitionProvider())->lookup(
                    $options->root,
                    dirname($options->index) . '/scip-index.scip',
                    $query,
                );
            }

            echo $this->render($query, $evidence, $options->format);

            return $evidence->status === 'answered' ? 0 : 1;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $argv */
    private function options(array $argv): CliOptions
    {
        array_shift($argv);

        return CliOptions::parse($argv, $this->artifacts, $this->defaultRoot);
    }

    private function containsPhpSource(string $root): bool
    {
        $skipDirectories = [
            '.agent-map' => true,
            '.git' => true,
            'build' => true,
            'coverage' => true,
            'dist' => true,
            'node_modules' => true,
            'vendor' => true,
        ];
        $directory = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static fn (SplFileInfo $item): bool => !$item->isDir() || !isset($skipDirectories[$item->getFilename()]),
        );
        $iterator = new RecursiveIteratorIterator($filter);
        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.php')) {
                return true;
            }
        }

        return false;
    }

    private function render(string $query, DefinitionEvidence $evidence, string $format): string
    {
        $payload = [
            'type' => 'definition_evidence',
            'title' => $query,
            'query' => $query,
            ...$evidence->toArray(),
        ];

        if ($format === 'json') {
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($format === 'toon') {
            return Toon::encode($payload) . "\n";
        }

        $text = 'Definition: ' . $query . "\n";
        $text .= '- status: ' . $evidence->status . "\n";
        $text .= '- provider: ' . $evidence->provider . "\n";
        $text .= '- capability: ' . $evidence->capability . "\n";
        if ($evidence->reason !== null) {
            $text .= '- reason: ' . $evidence->reason . "\n";
        }
        foreach ($evidence->toolchain as $name => $version) {
            $text .= '- ' . $name . ': ' . $version . "\n";
        }
        foreach ($evidence->metrics as $name => $value) {
            $text .= '- ' . $name . ': ' . $value . "\n";
        }
        foreach ($evidence->definitions as $definition) {
            $text .= sprintf(
                "  %s:%d-%d  %s\n",
                $definition->file,
                $definition->lineStart,
                $definition->lineEnd,
                $definition->symbolId,
            );
        }

        return $format === 'markdown'
            ? '## ' . $query . "\n\n```text\n" . $text . "```\n"
            : $text;
    }
}
