<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Cli\CliOptions;

final class CliBackendSelectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-cli-backend-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Foo.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo {}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultBackendRemainsAuto(): void
    {
        $options = CliOptions::parse(['build', '--root=' . $this->root]);

        self::assertSame('auto', $options->backend);
    }

    public function testExplicitStructuralBackendDoesNotTouchProjectPhpStanConfig(): void
    {
        file_put_contents(
            $this->root . '/phpstan.neon',
            "includes:\n    - definitely-missing-bootstrap.neon\n",
        );
        $out = $this->root . '/map.json';

        ob_start();
        try {
            $exit = (new AgentMapApplication())->run([
                'agent-map',
                'build',
                '--root=' . $this->root,
                '--paths=src',
                '--out=' . $out,
                '--backend=structural',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertFileExists($out);
        $map = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertSame('simple-php-code-parser+structural-only', $map['backend'] ?? null);
    }

    public function testExplicitPhpStanBackendIsRepresentedInParsedOptions(): void
    {
        $options = CliOptions::parse([
            'build',
            '--root=' . $this->root,
            '--backend=phpstan',
        ]);

        self::assertSame('phpstan', $options->backend);
    }

    public function testStructuralBackendAcceptsPhpStanSpecificOptionsButKeepsThemInert(): void
    {
        $marker = $this->root . '/phpstan-bootstrap-ran';
        file_put_contents(
            $this->root . '/phpstan-bootstrap.php',
            '<?php file_put_contents(' . var_export($marker, true) . ", 'ran');\n",
        );
        file_put_contents(
            $this->root . '/phpstan-security.neon',
            "parameters:\n    bootstrapFiles:\n        - phpstan-bootstrap.php\n",
        );

        $options = CliOptions::parse([
            'build',
            '--root=' . $this->root,
            '--backend=structural',
            '--phpstan-config=phpstan-security.neon',
            '--phpstan-memory-limit=64M',
        ]);

        self::assertSame('structural', $options->backend);
        self::assertSame('phpstan-security.neon', $options->phpStanConfig);
        self::assertSame('64M', $options->phpStanMemoryLimit);

        $out = $this->root . '/map-with-inert-phpstan-options.json';
        ob_start();
        try {
            $exit = (new AgentMapApplication())->run([
                'agent-map',
                'build',
                '--root=' . $this->root,
                '--paths=src',
                '--out=' . $out,
                '--backend=structural',
                '--phpstan-config=phpstan-security.neon',
                '--phpstan-memory-limit=64M',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertFileDoesNotExist($marker, 'Structural mode must not execute PHPStan bootstrap files.');
        self::assertFileExists($out);
        $map = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertSame('simple-php-code-parser+structural-only', $map['backend'] ?? null);
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDirectory($path . '/' . $entry);
        }
        rmdir($path);
    }
}
