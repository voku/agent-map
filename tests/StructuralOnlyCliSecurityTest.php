<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;

final class StructuralOnlyCliSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-structural-security-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Example.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Example {}\n",
        );
        file_put_contents(
            $this->root . '/phpstan.neon',
            "includes:\n    - definitely-missing-security-regression.neon\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExplicitStructuralOnlyBuildDoesNotLoadProjectPhpStanConfiguration(): void
    {
        $out = $this->root . '/map.json';

        ob_start();
        $exit = (new AgentMapApplication())->run([
            'agent-map',
            'build',
            '--root=' . $this->root,
            '--paths=src',
            '--out=' . $out,
            '--structural-only',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertFileExists($out);

        $map = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertSame('simple-php-code-parser+structural-only', $map['backend'] ?? null);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
