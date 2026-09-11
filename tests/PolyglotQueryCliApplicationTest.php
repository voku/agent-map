<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Cli\CliApplication;
use voku\AgentMap\Cli\PolyglotQueryCliApplication;

final class PolyglotQueryCliApplicationTest extends TestCase
{
    private string $root;
    private string $bin;
    private string $originalPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-polyglot-' . bin2hex(random_bytes(6));
        $this->bin = $this->root . '/bin';
        mkdir($this->bin, 0o775, true);
        $path = getenv('PATH');
        $this->originalPath = is_string($path) ? $path : '';
        putenv('PATH=' . $this->bin . PATH_SEPARATOR . $this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        $this->removeDirectory($this->root);
    }

    public function testJavaScriptQueryBuildsFreshScipIndexAndReturnsDefinition(): void
    {
        file_put_contents($this->root . '/package.json', "{}\n");
        mkdir($this->root . '/src/graph', 0o775, true);
        file_put_contents($this->root . '/src/graph/call-graph.js', "export function buildCallGraph() {}\n");
        $this->writeIndexer('scip-typescript', 'scip-typescript 0.4.0');
        $this->writeScip(<<<'JSON'
{"documents":[{"relative_path":"src/graph/call-graph.js","occurrences":[{"range":[10,1,10,15],"symbol":"scip-typescript npm sigmap 8.31.0 src/graph/call-graph.js/buildCallGraph().","symbol_roles":1}]}]}
JSON);

        $result = $this->runCli(['agent-map', 'query', 'buildCallGraph', '--root=' . $this->root, '--format=json']);
        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame(0, $result['exit']);
        self::assertSame('answered', $payload['status'] ?? null);
        self::assertSame('scip', $payload['provider'] ?? null);
        self::assertSame('definition', $payload['capability'] ?? null);
        self::assertSame('src/graph/call-graph.js', $payload['definitions'][0]['file'] ?? null);
        self::assertSame(11, $payload['definitions'][0]['line_start'] ?? null);
        self::assertFileExists($this->root . '/.agent-map/scip-index.scip');
        self::assertSame([], glob($this->root . '/.agent-map-scip-*.json') ?: []);
    }

    public function testPythonQueryUsesPythonIndexerAndCamelCaseScipFields(): void
    {
        file_put_contents($this->root . '/pyproject.toml', "[project]\nname = \"demo\"\n");
        mkdir($this->root . '/src/demo', 0o775, true);
        file_put_contents($this->root . '/src/demo/app.py', "class Flask:\n    pass\n");
        $this->writeIndexer('scip-python', 'scip-python 0.6.6');
        $this->writeScip(<<<'JSON'
{"documents":[{"relativePath":"src/demo/app.py","occurrences":[{"range":[4,0,5],"symbol":"scip-python python demo 0.0 src/demo/app.py/Flask#","symbolRoles":1}]}]}
JSON);

        $result = $this->runCli(['agent-map', 'query', 'Flask', '--root=' . $this->root, '--format=json']);
        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame(0, $result['exit']);
        self::assertSame('answered', $payload['status'] ?? null);
        self::assertSame('src/demo/app.py', $payload['definitions'][0]['file']);
        self::assertSame(5, $payload['definitions'][0]['line_start']);
        self::assertSame('scip-python 0.6.6', $payload['toolchain']['scip-python']);
    }

    public function testMissingToolchainIsUnavailableRatherThanNotFound(): void
    {
        file_put_contents($this->root . '/package.json', "{}\n");
        $emptyBin = $this->root . '/empty-bin';
        mkdir($emptyBin);
        putenv('PATH=' . $emptyBin);

        $result = $this->runCli(['agent-map', 'query', 'buildCallGraph', '--root=' . $this->root, '--format=json']);
        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame(1, $result['exit']);
        self::assertSame('unavailable', $payload['status'] ?? null);
        self::assertIsString($payload['reason'] ?? null);
        self::assertStringContainsString('Missing executable', $payload['reason']);
        self::assertSame([], $payload['definitions'] ?? null);
    }

    public function testExistingPhpMapKeepsPolyglotRouterOutOfThePath(): void
    {
        mkdir($this->root . '/.agent-map', 0o775, true);
        file_put_contents($this->root . '/.agent-map/php-symbols.json', "{}\n");

        $router = new PolyglotQueryCliApplication(defaultRoot: $this->root);

        self::assertFalse($router->supports(['agent-map', 'query', 'Anything']));
    }

    private function writeIndexer(string $name, string $version): void
    {
        $script = <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
  echo "__VERSION__"
  exit 0
fi
output=''
while [ "$#" -gt 0 ]; do
  if [ "$1" = "--output" ]; then
    shift
    output="$1"
  fi
  shift
done
if [ -z "$output" ]; then
  echo 'missing --output' >&2
  exit 2
fi
mkdir -p "$(dirname "$output")"
printf 'fake scip index' > "$output"
SH;
        $this->writeExecutable($name, str_replace('__VERSION__', $version, $script));
    }

    private function writeScip(string $json): void
    {
        $script = <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
  echo 'scip 0.9.0'
  exit 0
fi
if [ "$1" = "print" ] && [ "$2" = "--json" ]; then
  cat <<'JSON'
__JSON__
JSON
  exit 0
fi
echo 'unexpected scip invocation' >&2
exit 2
SH;
        $this->writeExecutable('scip', str_replace('__JSON__', $json, $script));
    }

    private function writeExecutable(string $name, string $script): void
    {
        $path = $this->bin . '/' . $name;
        if (file_put_contents($path, $script) === false || !chmod($path, 0o775)) {
            throw new RuntimeException('Unable to create fake executable: ' . $name);
        }
    }

    /** @param list<string> $argv @return array{exit: int, output: string} */
    private function runCli(array $argv): array
    {
        ob_start();
        $exit = (new CliApplication())->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
