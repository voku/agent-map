<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Search\SearchIndexStore;

/**
 * Two files declaring the same class name produce the same canonical symbol id,
 * and therefore the same chunk id. A build says so and keeps going; a refresh
 * used to abort the whole transaction on the UNIQUE constraint, because it
 * deletes only the paths it replaces and so met a surviving row it had not
 * counted.
 *
 * The condition is real in ordinary repositories - legacy duplication, fixtures,
 * vendored copies - and it left them able to build a search index but never
 * refresh one.
 *
 * @internal
 */
final class SearchRefreshDuplicateSymbolIdTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; the derived index is optional by design.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-search-duplicate-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/first', 0o775, true);
        mkdir($this->root . '/src/second', 0o775, true);
        $this->writeDuplicate('first', 'first');
        $this->writeDuplicate('second', 'second');
        file_put_contents(
            $this->root . '/src/Edited.php',
            "<?php\n\nfinal class Edited\n{\n    public function value(): string\n    {\n        return 'before';\n    }\n}\n",
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testRefreshToleratesADuplicateCanonicalIdExactlyAsBuildDoes(): void
    {
        $index = $this->root . '/map.json';
        $database = $this->root . '/search.sqlite';

        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));
        self::assertSame(0, $this->cli(['search-index', 'build', '--root=' . $this->root, '--index=' . $index, '--database=' . $database]));

        $afterBuild = (new SearchIndexStore($database))->chunkCount();
        self::assertGreaterThan(0, $afterBuild);

        // Change one unrelated file, so the refresh replaces its paths only and the
        // duplicate-id rows of the untouched files survive the delete.
        file_put_contents(
            $this->root . '/src/Edited.php',
            "<?php\n\nfinal class Edited\n{\n    public function value(): string\n    {\n        return 'after';\n    }\n}\n",
        );
        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));

        self::assertSame(0, $this->cli(['search-index', 'refresh', '--root=' . $this->root, '--index=' . $index, '--database=' . $database]));
        self::assertSame(0, $this->cli(['search-index', 'doctor', '--root=' . $this->root, '--index=' . $index, '--database=' . $database]));

        $store = new SearchIndexStore($database);
        self::assertSame([], $store->integrityFailures());

        // The changed file really was re-chunked, and the duplicated canonical id
        // still holds exactly one row - the same resolution a full build reaches.
        self::assertSame(['after', 'after'], $this->markersFor($store, 'src/Edited.php'));
        self::assertSame(
            ['class:Duplicated#overview:v1', 'method:Duplicated::which#body:v1'],
            $this->chunkIdsFor($store, 'src/first/Duplicated.php', 'src/second/Duplicated.php'),
        );
    }

    public function testARefreshedIndexHoldsTheSameChunksAFullBuildWouldHold(): void
    {
        $index = $this->root . '/map.json';
        $refreshed = $this->root . '/refreshed.sqlite';
        $rebuilt = $this->root . '/rebuilt.sqlite';

        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));
        self::assertSame(0, $this->cli(['search-index', 'build', '--root=' . $this->root, '--index=' . $index, '--database=' . $refreshed]));

        file_put_contents(
            $this->root . '/src/Edited.php',
            "<?php\n\nfinal class Edited\n{\n    public function value(): string\n    {\n        return 'after';\n    }\n}\n",
        );
        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));

        self::assertSame(0, $this->cli(['search-index', 'refresh', '--root=' . $this->root, '--index=' . $index, '--database=' . $refreshed]));
        self::assertSame(0, $this->cli(['search-index', 'build', '--root=' . $this->root, '--index=' . $index, '--database=' . $rebuilt]));

        // Build-vs-refresh parity is the actual contract: an incremental refresh
        // must land on the same chunk set a full build would produce, duplicates
        // resolved the same way.
        self::assertSame(
            $this->chunkIdentities($rebuilt),
            $this->chunkIdentities($refreshed),
        );
    }

    /** @return list<string> */
    private function chunkIdentities(string $database): array
    {
        $identities = [];
        foreach ((new SearchIndexStore($database))->chunkContentsForPaths() as $row) {
            $identities[] = $row['chunk_id'] . '@' . $row['content_sha256'];
        }
        sort($identities, SORT_STRING);

        return $identities;
    }

    /** @return list<string> */
    private function markersFor(SearchIndexStore $store, string $path): array
    {
        $markers = [];
        foreach ($store->chunkContentsForPaths([$path]) as $row) {
            $markers[] = str_contains($row['content'], 'after') ? 'after' : 'before';
        }

        return $markers;
    }

    /** @return list<string> */
    private function chunkIdsFor(SearchIndexStore $store, string ...$paths): array
    {
        $ids = [];
        foreach ($store->chunkContentsForPaths(array_values($paths)) as $row) {
            $ids[] = $row['chunk_id'];
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function writeDuplicate(string $directory, string $marker): void
    {
        file_put_contents(
            $this->root . '/src/' . $directory . '/Duplicated.php',
            "<?php\n\nfinal class Duplicated\n{\n    public function which(): string\n    {\n        return '" . $marker . "';\n    }\n}\n",
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

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
