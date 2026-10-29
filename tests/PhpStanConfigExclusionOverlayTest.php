<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;

final class PhpStanConfigExclusionOverlayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Real PHPStan configuration merge requires phpstan/phpstan.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-config-overlay-' . bin2hex(random_bytes(6));
        foreach (['src/projectAnalyse', 'src/projectFull', 'src/mapOnly', 'src'] as $directory) {
            if (!is_dir($this->root . '/' . $directory)) {
                mkdir($this->root . '/' . $directory, 0775, true);
            }
        }

        $this->writePhp('src/projectAnalyse/Excluded.php', 'MissingProjectAnalyse');
        $this->writePhp('src/projectFull/Excluded.php', 'MissingProjectFull');
        $this->writePhp('src/mapOnly/Excluded.php', 'MissingAgentMapOnly');
        $this->writePhp('src/Control.php', 'MissingControl');
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * The user's analysis-only and fully excluded entries must survive the
     * auto-generated agent-map exclusion list without reducing analysed scope.
     */
    public function testNamedUserExcludePathsSurviveGeneratedAnalysePaths(): void
    {
        $this->writeProjectConfig(
            "        analyse:\n"
            . '            - ' . $this->quoted('src/projectAnalyse') . "\n"
            . "        analyseAndScan:\n"
            . '            - ' . $this->quoted('src/projectFull') . "\n",
        );
        $output = $this->analyseOverlay();

        self::assertStringContainsString('MissingControl', $output, 'The control file must be analysed.');
        self::assertStringNotContainsString('MissingProjectAnalyse', $output);
        self::assertStringNotContainsString('MissingProjectFull', $output);
        self::assertStringNotContainsString('MissingAgentMapOnly', $output);
    }

    /**
     * PHPStan still accepts flat excludePaths lists. A generated keyed
     * excludePaths.analyse must not silently remove these user settings.
     */
    public function testLegacyFlatUserExcludePathsSurviveGeneratedKeyedPaths(): void
    {
        $this->writeProjectConfig(
            '        - ' . $this->quoted('src/projectAnalyse') . "\n"
            . '        - ' . $this->quoted('src/projectFull') . "\n",
        );
        $output = $this->analyseOverlay();

        self::assertStringContainsString('MissingControl', $output, 'The control file must be analysed.');
        self::assertStringNotContainsString('MissingProjectAnalyse', $output);
        self::assertStringNotContainsString('MissingProjectFull', $output);
        self::assertStringNotContainsString('MissingAgentMapOnly', $output);
    }

    private function writePhp(string $path, string $missingType): void
    {
        file_put_contents(
            $this->root . '/' . $path,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace OverlayProbe;\n"
            . "final class " . $missingType . "Check {\n"
            . "    public function run(): void {\n"
            . "        new \\" . $missingType . "();\n"
            . "    }\n}\n",
        );
    }

    private function quoted(string $relative): string
    {
        return json_encode($this->root . '/' . $relative, JSON_THROW_ON_ERROR);
    }

    private function writeProjectConfig(string $excludePaths): void
    {
        file_put_contents(
            $this->root . '/project.neon',
            "parameters:\n"
            . "    level: 0\n"
            . "    excludePaths:\n"
            . $excludePaths,
        );
    }

    private function analyseOverlay(): string
    {
        $phpStanPath = InstalledVersions::getInstallPath('phpstan/phpstan');
        if (!is_string($phpStanPath) || !is_file($phpStanPath . '/phpstan.phar')) {
            throw new RuntimeException('The installed PHPStan PHAR could not be found.');
        }

        // Invoke the actual production overlay generator, not a duplicate
        // handwritten configuration from the test.
        $method = new ReflectionMethod(PhpStanSemanticAnalyzer::class, 'overlayConfiguration');
        $overlay = $method->invoke(
            new PhpStanSemanticAnalyzer(),
            $this->root . '/project.neon',
            [$this->root . '/src'],
            [],
            [$this->root . '/src/mapOnly'],
            $this->root . '/cache',
        );
        self::assertIsString($overlay);
        file_put_contents($this->root . '/agent-map.neon', $overlay);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $command = [
            PHP_BINARY,
            $phpStanPath . '/phpstan.phar',
            'analyse',
            '--configuration=' . $this->root . '/agent-map.neon',
            '--autoload-file=' . $autoload,
            '--no-progress',
            '--error-format=raw',
        ];

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start PHPStan.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $output = (string) $stdout . "\n" . (string) $stderr;

        // The control file intentionally contains a missing class, so PHPStan
        // must return errors. A configuration failure is reported separately.
        self::assertSame(1, $status, $output);

        return $output;
    }
}
