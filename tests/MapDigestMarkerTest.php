<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\DiagnosticEntry;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\LocalBindingEntry;
use voku\AgentMap\Index\LocalExitEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\MapArtifactPaths;

/**
 * The build-time digest may replace recomputation only for the exact bytes it was
 * recorded for; every other situation must fall back to the computed digest.
 */
final class MapDigestMarkerTest extends TestCase
{
    private const FORGED = 'sha256:0000000000000000000000000000000000000000000000000000000000000000';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-digest-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        IndexReader::clearCache();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->root);
        IndexReader::clearCache();
    }

    public function testWrittenDigestEqualsTheDigestOfTheMapAsReadBack(): void
    {
        foreach (['php-symbols.json', 'php-symbols.toon', 'custom.json', 'custom.toon'] as $name) {
            $file = $this->root . '/' . $name;
            $index = $this->index();
            (new IndexWriter())->write($index, $file);

            $marker = $this->marker($file);
            self::assertSame($this->computedDigest($index), $marker['map_digest'], $name);

            // Without the marker the reader recomputes; with it the reader trusts it. Both must agree.
            $withMarker = $this->read($file)->mapDigest();
            rename(MapArtifactPaths::mapDigestFor($file), $file . '.marker-off');
            $recomputed = $this->read($file)->mapDigest();
            rename($file . '.marker-off', MapArtifactPaths::mapDigestFor($file));

            self::assertSame($recomputed, $withMarker, $name);
            self::assertSame($marker['map_digest'], $recomputed, $name);
        }
    }

    public function testReaderUsesTheMarkerOnlyForTheExactBytesItRecords(): void
    {
        $file = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->index(), $file);
        $real = $this->read($file)->mapDigest();

        // A marker whose byte hashes match is trusted, which proves the reader really uses it.
        $this->forgeMarkerDigest($file);
        self::assertSame(self::FORGED, $this->read($file)->mapDigest());

        // Same size, one byte changed in the relations companion: the marker must be ignored.
        $relations = MapArtifactPaths::relationsFileFor($file);
        $bytes = (string) file_get_contents($relations);
        file_put_contents($relations, str_replace('src/Caller.php', 'src/Callee.php', $bytes));
        self::assertNotSame(self::FORGED, $this->read($file)->mapDigest());
        file_put_contents($relations, $bytes);

        // A symbols file edited after publishing is ignored the same way.
        $symbols = (string) file_get_contents($file);
        file_put_contents($file, str_replace('"Demo"', '"Dema"', $symbols));
        self::assertNotSame(self::FORGED, $this->read($file)->mapDigest());
        file_put_contents($file, $symbols);

        self::assertSame(self::FORGED, $this->read($file)->mapDigest(), 'restored bytes match again');

        // Partial loads were never the map the writer hashed and keep computing their own digest.
        $partial = (new IndexReader())->read($file, loadRelations: false);
        self::assertNotSame(self::FORGED, $partial->mapDigest());
        IndexReader::clearCache();
        self::assertNotSame(self::FORGED, (new IndexReader())->readSections($file, ['files'])->mapDigest());

        unlink(MapArtifactPaths::mapDigestFor($file));
        self::assertSame($real, $this->read($file)->mapDigest(), 'no marker: computed as before');
    }

    public function testRelationsCompanionFromAnotherBuildIsNotVouchedFor(): void
    {
        $file = $this->root . '/php-symbols.json';
        $writer = new IndexWriter();
        $writer->write($this->index(), $file);
        $oldRelations = (string) file_get_contents(MapArtifactPaths::relationsFileFor($file));

        $changed = $this->index(extraRelation: true);
        $writer->write($changed, $file);
        $this->forgeMarkerDigest($file);

        // Torn pair: new symbols file, previous build's relations file.
        file_put_contents(MapArtifactPaths::relationsFileFor($file), $oldRelations);
        $read = $this->read($file);

        self::assertNotSame(self::FORGED, $read->mapDigest());
        self::assertSame($this->computedDigest($read), $read->mapDigest());
    }

    public function testMalformedOrForeignMarkersAreIgnored(): void
    {
        $file = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->index(), $file);
        $real = $this->read($file)->mapDigest();
        $markerFile = MapArtifactPaths::mapDigestFor($file);
        $valid = $this->marker($file);

        $variants = [
            'not json' => '{',
            'not an object' => '"x"',
            'future schema' => ['schema_version' => 2] + $valid,
            'other hash' => ['byte_hash' => 'md5'] + $valid,
            'digest not sha256' => ['map_digest' => 'sha256:nothex'] + $valid,
            'missing relations hash' => array_diff_key($valid, ['relations' => true]),
        ];
        foreach ($variants as $label => $variant) {
            file_put_contents($markerFile, is_string($variant) ? $variant : json_encode($variant));
            self::assertSame($real, $this->read($file)->mapDigest(), $label);
        }
    }

    private function forgeMarkerDigest(string $file): void
    {
        $marker = $this->marker($file);
        $marker['map_digest'] = self::FORGED;
        file_put_contents(MapArtifactPaths::mapDigestFor($file), json_encode($marker));
    }

    /** @return array<string, mixed> */
    private function marker(string $file): array
    {
        $markerFile = MapArtifactPaths::mapDigestFor($file);
        self::assertFileExists($markerFile);
        $data = json_decode((string) file_get_contents($markerFile), true);
        self::assertIsArray($data);

        return $data;
    }

    private function read(string $file): AgentMapIndex
    {
        IndexReader::clearCache();

        return (new IndexReader())->read($file);
    }

    private function computedDigest(AgentMapIndex $index): string
    {
        $data = $index->toArray();
        unset($data['root']);

        return 'sha256:' . hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function index(bool $extraRelation = false): AgentMapIndex
    {
        $method = new MethodEntry('validate', 'public', 2, 4, nativeReturnType: '?object', resolvedReturnType: 'User|null', reconciliationStatus: 'semantic_enrichment');
        $symbol = new SymbolEntry('class', 'Validator', 'Demo\\Validator', 1, 5, [$method], reconciliationStatus: 'confirmed');
        $relations = [
            RelationEntry::create('method:Demo\\Caller::run', 'calls', ['method:Demo\\Validator::validate'], 'src/Caller.php', 10, 10, 'phpstan_resolved', 'Demo\\Validator', 'User|null', 120, 150),
        ];
        if ($extraRelation) {
            $relations[] = RelationEntry::create('method:Demo\\Caller::run', 'references_type', ['class:Demo\\Validator'], 'src/Caller.php', 11, 11, 'phpstan_resolved');
        }

        return new AgentMapIndex(
            schemaVersion: AgentMapIndex::SCHEMA_VERSION,
            root: $this->root,
            backend: 'phpstan',
            files: [new FileEntry('src/Validator.php', 'sha256:' . str_repeat('a', 64), 'Demo', [$symbol])],
            relations: $relations,
            diagnostics: [new DiagnosticEntry('d1', 'warning', 'conflict', "Ümlaut \"quoted\" / slash", 'class:Demo\\Validator', 'src/Validator.php', 1)],
            fingerprint: new AnalysisFingerprint('2.3.6', 'sha256:cfg', 'sha256:lock', 'sha256:src'),
            localBindings: [new LocalBindingEntry('b1', 'method:Demo\\Validator::validate', '$user', 'src/Validator.php', 3, 3, 'User', 'new', null, 40, 60)],
            localExits: [new LocalExitEntry('e1', 'method:Demo\\Validator::validate', 'return', 'src/Validator.php', 4, 4, 'User|null')],
        );
    }
}
