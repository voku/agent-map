<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Search\SearchIndexStore;

/**
 * A partial refresh must preserve the same duplicate winner a clean full build selects.
 *
 * The canonical map orders files by path, and Search keeps the first chunk that claims a canonical
 * id. If an index already contains a later path and a refresh introduces an earlier duplicate, the
 * retained row must not win merely because it survived the partial delete.
 *
 * @internal
 */
final class SearchRefreshDuplicateWinnerParityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; the derived index is optional by design.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-search-duplicate-winner-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/first', 0o775, true);
        mkdir($this->root . '/src/second', 0o775, true);
        $this->writeDuplicate('second', 'retainedloser');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testAddingEarlierDuplicateThroughRefreshMatchesCleanBuild(): void
    {
        $index = $this->root . '/map.json';
        $refreshed = $this->root . '/refreshed.sqlite';
        $rebuilt = $this->root . '/rebuilt.sqlite';

        // The first search snapshot legitimately contains only the later path.
        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));
        self::assertSame(0, $this->cli(['search-index', 'build', '--root=' . $this->root, '--index=' . $index, '--database=' . $refreshed]));
        self::assertSame(
            ['method:Duplicated::which#body:v1@src/second/Duplicated.php'],
            $this->searchableRows($refreshed, 'retainedloser'),
        );

        // The new path sorts earlier and therefore wins a clean full build. Only this newly added
        // path is absent from the existing search snapshot, so refresh re-extracts one side while
        // the old claimant survives until duplicate precedence is resolved in the store.
        $this->writeDuplicate('first', 'canonicalwinner');
        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));

        self::assertSame(0, $this->cli(['search-index', 'refresh', '--root=' . $this->root, '--index=' . $index, '--database=' . $refreshed]));
        self::assertSame(0, $this->cli(['search-index', 'build', '--root=' . $this->root, '--index=' . $index, '--database=' . $rebuilt]));

        self::assertSame(
            $this->searchableRows($rebuilt, 'canonicalwinner'),
            $this->searchableRows($refreshed, 'canonicalwinner'),
            'one-sided refresh must expose the same searchable duplicate winner as a clean build',
        );
        self::assertSame(
            ['method:Duplicated::which#body:v1@src/first/Duplicated.php'],
            $this->searchableRows($refreshed, 'canonicalwinner'),
        );
        self::assertSame([], $this->searchableRows($refreshed, 'retainedloser'));
        self::assertSame(
            $this->chunkIdentities($rebuilt),
            $this->chunkIdentities($refreshed),
            'one-sided refresh and clean build must land on identical chunk identities and content',
        );
        self::assertSame([], (new SearchIndexStore($refreshed))->integrityFailures());
    }

    /** @return list<string> */
    private function searchableRows(string $database, string $query): array
    {
        $rows = [];
        foreach ((new SearchIndexStore($database))->searchLexical($query, 10) as $row) {
            $rows[] = $row['chunk_id'] . '@' . $row['file_path'];
        }
        sort($rows, SORT_STRING);

        return $rows;
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
