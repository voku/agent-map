<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Search\SearchIndexStore;

/**
 * A refresh that finds no changed chunk still has to reconcile what `doctor`
 * checks. The map identity moves on a rebuild and when files leave the scope,
 * neither of which changes the content of a surviving file.
 *
 * @internal
 */
final class SearchRefreshSnapshotReconcileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; the derived index is optional by design.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-search-reconcile-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Kept.php', "<?php\n\nfinal class Kept\n{\n    public function keep(): string\n    {\n        return 'kept';\n    }\n}\n");
        file_put_contents($this->root . '/src/Dropped.php', "<?php\n\nfinal class Dropped\n{\n    public function drop(): string\n    {\n        return 'dropped';\n    }\n}\n");
    }

    protected function tearDown(): void
    {
        if (!isset($this->root)) {
            return;
        }
        $this->removeDirectory($this->root);
    }

    public function testRefreshWithoutChangedChunksStillReconcilesTheSnapshotAndPrunes(): void
    {
        $index = $this->root . '/map.json';
        $database = $this->root . '/search.sqlite';

        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));
        self::assertSame(0, $this->cli(['search', 'build', '--index=' . $index, '--database=' . $database]));
        self::assertSame(0, $this->cli(['search', 'doctor', '--index=' . $index, '--database=' . $database]));

        // Drop one file from the scope. No surviving file changes, but the map
        // identity does - exactly the case the early return used to skip.
        unlink($this->root . '/src/Dropped.php');
        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));

        self::assertSame(0, $this->cli(['search', 'refresh', '--index=' . $index, '--database=' . $database]));

        self::assertSame(
            0,
            $this->cli(['search', 'doctor', '--index=' . $index, '--database=' . $database]),
            'a refresh that reports "up to date" must leave doctor satisfied, not permanently mismatched',
        );
    }

    /** @param list<string> $arguments */
    private function cli(array $arguments): int
    {
        ob_start();
        try {
            return (new AgentMapApplication())->run(['agent-map', ...$arguments]);
        } finally {
            ob_end_clean();
        }
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
