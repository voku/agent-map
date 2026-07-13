<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Cli\AgentMapApplication;

final class AgentMapApplicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-app-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents($this->root . '/src/EvidenceValidator.php', $this->source('EvidenceValidator'));
        file_put_contents($this->root . '/tests/EvidenceValidatorTest.php', $this->source('EvidenceValidatorTest', 'Demo\Tests'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSummaryStatsRelatedAndFormats(): void
    {
        $this->runApp(['agent-map', 'build', '--root=' . $this->root, '--paths=src,tests', '--out=' . $this->root . '/map.json']);

        $summary = $this->runApp(['agent-map', 'summary', '--index=' . $this->root . '/map.json']);
        self::assertStringContainsString('Agent Map Summary', $summary['output']);
        self::assertStringContainsString('Files indexed: 2', $summary['output']);

        $stats = $this->runApp(['agent-map', 'stats', '--index=' . $this->root . '/map.json']);
        self::assertStringContainsString('Methods:', $stats['output']);

        $related = $this->runApp(['agent-map', 'related', 'EvidenceValidator', '--index=' . $this->root . '/map.json', '--method-limit=1']);
        self::assertStringContainsString('Likely tests:', $related['output']);
        self::assertStringContainsString('tests/EvidenceValidatorTest.php', $related['output']);

        $json = $this->runApp(['agent-map', 'query', 'EvidenceValidator', '--index=' . $this->root . '/map.json', '--format=json']);
        self::assertJson($json['output']);

        $toon = $this->runApp(['agent-map', 'query', 'EvidenceValidator', '--index=' . $this->root . '/map.json', '--format=toon']);
        self::assertStringContainsString('query: EvidenceValidator', $toon['output']);
    }

    public function testChangedUsesGitDiffAgainstBase(): void
    {
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.email', 'agent-map@example.test']);
        $this->git(['config', 'user.name', 'Agent Map']);
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'initial']);
        $this->git(['checkout', '-b', 'work']);
        file_put_contents($this->root . '/src/EvidenceValidator.php', $this->source('EvidenceValidator') . "\n// changed\n");

        $this->runApp(['agent-map', 'build', '--root=' . $this->root, '--paths=src,tests', '--out=' . $this->root . '/map.json']);
        $changed = $this->runApp(['agent-map', 'changed', '--index=' . $this->root . '/map.json', '--base=main']);

        self::assertSame(0, $changed['exit']);
        self::assertStringContainsString('Changed PHP files:', $changed['output']);
        self::assertStringContainsString('src/EvidenceValidator.php', $changed['output']);
    }

    public function testChangedIncludesUntrackedPhpFiles(): void
    {
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.email', 'agent-map@example.test']);
        $this->git(['config', 'user.name', 'Agent Map']);
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'initial']);
        file_put_contents($this->root . '/src/NewService.php', $this->source('NewService'));

        $this->runApp(['agent-map', 'build', '--root=' . $this->root, '--paths=src,tests', '--out=' . $this->root . '/map.json']);
        $changed = $this->runApp(['agent-map', 'changed', '--index=' . $this->root . '/map.json', '--base=main']);

        self::assertSame(0, $changed['exit']);
        self::assertStringContainsString('src/NewService.php', $changed['output']);
    }

    public function testRelatedSkipsSymbolLessMentionFiles(): void
    {
        file_put_contents($this->root . '/src/NoSymbols.php', "<?php\n// EvidenceValidator\n");
        file_put_contents($this->root . '/src/Reference.php', $this->source('Reference') . "\n// EvidenceValidator\n");

        $this->runApp(['agent-map', 'build', '--root=' . $this->root, '--paths=src,tests', '--out=' . $this->root . '/map.json']);
        $related = $this->runApp(['agent-map', 'related', 'EvidenceValidator', '--index=' . $this->root . '/map.json']);

        self::assertStringContainsString('src/Reference.php', $related['output']);
        self::assertStringNotContainsString('src/NoSymbols.php', $related['output']);
    }

    /**
     * @param list<string> $argv
     * @return array{exit: int, output: string}
     */
    private function runApp(array $argv): array
    {
        ob_start();
        $exit = (new AgentMapApplication())->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args): void
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start git.');
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: 'git failed');
        }
    }

    private function source(string $class, string $namespace = 'Demo'): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        final class {$class}
        {
            public function run(): void
            {
            }
        }
        PHP;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
