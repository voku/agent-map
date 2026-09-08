<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentMap\Index\IndexReader;

/**
 * Restoring the provider an index's vectors were written with is a package
 * contract, not something each embedding host should rebuild.
 *
 * Before this existed, the only implementation lived in a private CLI method
 * that read the store's own metadata keys directly, so a consumer wanting the
 * semantic channel had to copy which key holds the weighting, how it is shaped,
 * and what makes it valid - a second definition of the vector space living
 * outside the package that owns it.
 *
 * @internal
 */
final class SearchSemanticProviderRestoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; the derived index is optional by design.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-semantic-restore-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Retry.php',
            "<?php\n\nfinal class Retry\n{\n    public function attempt(): string\n    {\n"
            . "        // Retry the upload with exponential backoff until it succeeds.\n"
            . "        return 'retried';\n    }\n}\n",
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testAnIndexWithNoVectorsHasNoSemanticProvider(): void
    {
        $store = new SearchIndexStore($this->root . '/empty.sqlite');

        // Never a freshly fitted provider: that would embed queries into a space
        // no stored vector belongs to.
        self::assertNull($store->semanticProvider());
    }

    public function testTheRestoredProviderMatchesTheModelTheVectorsBelongTo(): void
    {
        [$index, $database] = $this->buildIndex();

        $store = new SearchIndexStore($database);
        $this->requireVectors($store);

        $provider = $store->semanticProvider();
        self::assertNotNull($provider);
        self::assertSame(
            $provider->model()->fingerprint(),
            $store->meta('embedding_fingerprint'),
            'a restored provider that does not match the stored fingerprint must be refused, not returned',
        );

        // The consumer-facing point: a caller can now enable the semantic channel
        // through the package API alone.
        $result = (new HybridSearch(embeddings: $provider))
            ->search((new IndexReader())->read($index), $store, 'retry the upload', 5);

        self::assertFalse($result['degraded']);
        self::assertSame('structural+lexical+semantic', $result['effective_mode']);
    }

    public function testAFingerprintMismatchRefusesTheProvider(): void
    {
        [, $database] = $this->buildIndex();

        $store = new SearchIndexStore($database);
        $this->requireVectors($store);

        $store->setMeta('embedding_fingerprint', 'sha256:a-model-these-vectors-do-not-belong-to');

        self::assertNull($store->semanticProvider());
    }

    public function testUnusableRecordedStateRefusesTheProvider(): void
    {
        [, $database] = $this->buildIndex();

        $store = new SearchIndexStore($database);
        $this->requireVectors($store);

        $store->setMeta('embedding_state', '{"revision":"only-half-of-it"}');

        self::assertNull($store->semanticProvider());
    }

    /**
     * vectorCount() answers 0 until vector support has been enabled on the
     * instance, which is exactly what semanticProvider() does first.
     */
    private function requireVectors(SearchIndexStore $store): void
    {
        if (!$store->enableVectorSupport() || $store->vectorCount() === 0) {
            self::markTestSkipped('This runtime has no usable sqlite-vec, so no vectors were written.');
        }
    }

    /** @return array{0: string, 1: string} */
    private function buildIndex(): array
    {
        $index = $this->root . '/map.json';
        $database = $this->root . '/search.sqlite';

        self::assertSame(0, $this->cli(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']));
        self::assertSame(0, $this->cli(['search-index', 'build', '--root=' . $this->root, '--index=' . $index, '--database=' . $database]));

        return [$index, $database];
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
