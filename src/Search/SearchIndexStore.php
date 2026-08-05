<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use PDO;
use RuntimeException;

/**
 * The derived hybrid-search index: SQLite plus an FTS5 lexical index over the code chunks.
 *
 * Disposable by design. Everything in here can be rebuilt from the canonical map and the working
 * tree, which is why the map snapshot it was built from is recorded in `search_meta` - a result set
 * that cannot name the map it came from is not evidence.
 *
 * FTS5 external-content tables are not kept coherent by SQLite on their own. Every write path here
 * updates `code_chunks_fts` explicitly, and `integrityFailures()` exists so that assumption can be
 * checked rather than believed.
 */
final class SearchIndexStore
{
    public const string SCHEMA_VERSION = '1.0';

    private PDO $pdo;

    public function __construct(private readonly string $databaseFile)
    {
        $directory = dirname($this->databaseFile);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create search index directory: ' . $directory);
        }

        $this->pdo = new PDO('sqlite:' . $this->databaseFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
    }

    public static function supportsFts5(): bool
    {
        try {
            $probe = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $probe->exec('CREATE VIRTUAL TABLE fts5_probe USING fts5(content)');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function meta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM search_meta WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function setMeta(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO search_meta (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        );
        $statement->execute(['key' => $key, 'value' => $value]);
    }

    /**
     * Replaces every chunk of the given files. Passing null replaces the whole index, which is what
     * a full build does; passing the changed files is what a refresh does.
     *
     * Returns how many chunks were skipped because another chunk already claimed their id. That
     * happens in real repositories: two files declaring the same class name produce the same
     * canonical symbol id, which the map itself treats as a conflict. Search keeps the first and
     * reports the count rather than aborting the whole index over legacy duplication.
     *
     * @param list<CodeChunk> $chunks
     * @param list<string>|null $replacedPaths
     */
    public function replaceChunks(array $chunks, ?array $replacedPaths = null): int
    {
        $this->pdo->beginTransaction();

        try {
            if ($replacedPaths === null) {
                $this->deleteWhere('1 = 1', []);
            } else {
                foreach ($replacedPaths as $path) {
                    $this->deleteWhere('file_path = :path', ['path' => $path]);
                }
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO code_chunks
                    (chunk_id, symbol_id, file_path, symbol_name, chunk_kind, start_line, end_line,
                     source_sha256, content_sha256, signature, content)
                 VALUES
                    (:chunk_id, :symbol_id, :file_path, :symbol_name, :chunk_kind, :start_line, :end_line,
                     :source_sha256, :content_sha256, :signature, :content)',
            );
            $insertFts = $this->pdo->prepare(
                'INSERT INTO code_chunks_fts (rowid, symbol_name, signature, content)
                 VALUES (:rowid, :symbol_name, :signature, :content)',
            );

            $seen = [];
            $skipped = 0;
            foreach ($chunks as $chunk) {
                if (isset($seen[$chunk->chunkId])) {
                    ++$skipped;
                    continue;
                }
                $seen[$chunk->chunkId] = true;

                $insert->execute([
                    'chunk_id'      => $chunk->chunkId,
                    'symbol_id'     => $chunk->symbolId,
                    'file_path'     => $chunk->filePath,
                    'symbol_name'   => $chunk->symbolName,
                    'chunk_kind'    => $chunk->kind,
                    'start_line'    => $chunk->startLine,
                    'end_line'      => $chunk->endLine,
                    'source_sha256' => $chunk->sourceSha256,
                    'content_sha256' => $chunk->contentSha256,
                    'signature'     => $chunk->signature,
                    'content'       => $chunk->content,
                ]);
                $insertFts->execute([
                    'rowid'       => (int)$this->pdo->lastInsertId(),
                    'symbol_name' => $chunk->symbolName,
                    'signature'   => $chunk->signature,
                    'content'     => $chunk->content,
                ]);
            }

            $this->pdo->commit();

            return $skipped;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }

    /**
     * @return list<array{chunk_id: string, symbol_id: string, file_path: string, symbol_name: string,
     *                    chunk_kind: string, start_line: int, end_line: int, content_sha256: string,
     *                    signature: string, rank: float}>
     */
    public function searchLexical(string $query, int $limit): array
    {
        // Tried narrow first: requiring every token finds the chunk that is actually about the query.
        // Only when nothing matches does it widen to OR, which otherwise lets one common word drag in
        // every chunk that happens to contain it.
        $rows = $this->matchRows($this->toMatchExpression($query, ' AND '), $limit);

        return $rows === [] ? $this->matchRows($this->toMatchExpression($query, ' OR '), $limit) : $rows;
    }

    /**
     * @return list<array{chunk_id: string, symbol_id: string, file_path: string, symbol_name: string,
     *                    chunk_kind: string, start_line: int, end_line: int, content_sha256: string,
     *                    signature: string, rank: float}>
     */
    private function matchRows(?string $match, int $limit): array
    {
        if ($match === null) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT c.chunk_id, c.symbol_id, c.file_path, c.symbol_name, c.chunk_kind,
                    c.start_line, c.end_line, c.content_sha256, c.signature, bm25(code_chunks_fts) AS rank
             FROM code_chunks_fts
             JOIN code_chunks c ON c.rowid = code_chunks_fts.rowid
             WHERE code_chunks_fts MATCH :match
             ORDER BY rank
             LIMIT :limit',
        );
        $statement->bindValue('match', $match);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'chunk_id'       => (string)$row['chunk_id'],
                'symbol_id'      => (string)$row['symbol_id'],
                'file_path'      => (string)$row['file_path'],
                'symbol_name'    => (string)$row['symbol_name'],
                'chunk_kind'     => (string)$row['chunk_kind'],
                'start_line'     => (int)$row['start_line'],
                'end_line'       => (int)$row['end_line'],
                'content_sha256' => (string)$row['content_sha256'],
                'signature'      => (string)$row['signature'],
                'rank'           => (float)$row['rank'],
            ];
        }

        return $rows;
    }

    public function chunkCount(): int
    {
        return $this->countOf('SELECT COUNT(*) FROM code_chunks');
    }

    private function countOf(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? 0 : (int)$statement->fetchColumn();
    }

    /**
     * @return list<string> empty when the lexical index and the chunk table agree
     */
    public function integrityFailures(): array
    {
        $failures = [];

        $chunks = $this->chunkCount();
        $indexed = $this->countOf('SELECT COUNT(*) FROM code_chunks_fts');
        if ($chunks !== $indexed) {
            $failures[] = sprintf('lexical index holds %d rows for %d chunks', $indexed, $chunks);
        }

        try {
            $this->pdo->exec("INSERT INTO code_chunks_fts(code_chunks_fts) VALUES('integrity-check')");
        } catch (\PDOException $exception) {
            $failures[] = 'FTS5 integrity-check failed: ' . $exception->getMessage();
        }

        return $failures;
    }

    public function rebuildLexicalIndex(): void
    {
        $this->pdo->exec("INSERT INTO code_chunks_fts(code_chunks_fts) VALUES('rebuild')");
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function deleteWhere(string $condition, array $parameters): void
    {
        // The external-content FTS row has to be removed with the documented 'delete' command and the
        // *old* column values; deleting only from code_chunks leaves the lexical index pointing at
        // rows that no longer exist.
        $select = $this->pdo->prepare('SELECT rowid, symbol_name, signature, content FROM code_chunks WHERE ' . $condition);
        $select->execute($parameters);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        $deleteFts = $this->pdo->prepare(
            "INSERT INTO code_chunks_fts (code_chunks_fts, rowid, symbol_name, signature, content)
             VALUES ('delete', :rowid, :symbol_name, :signature, :content)",
        );
        foreach ($rows as $row) {
            $deleteFts->execute([
                'rowid'       => (int)$row['rowid'],
                'symbol_name' => (string)$row['symbol_name'],
                'signature'   => (string)$row['signature'],
                'content'     => (string)$row['content'],
            ]);
        }

        $delete = $this->pdo->prepare('DELETE FROM code_chunks WHERE ' . $condition);
        $delete->execute($parameters);
    }

    /**
     * FTS5 MATCH syntax is not free text: an unbalanced quote or a bare `-` is a syntax error, and a
     * query typed by a human regularly contains both. Every token is quoted instead, and question
     * words are dropped - "how are stale entries detected" must not rank a chunk because it contains
     * the word "how".
     */
    private function toMatchExpression(string $query, string $operator): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}_:\\\\]+/u', $query) ?: [];
        $quoted = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 3 || in_array(mb_strtolower($token), QueryPlanner::STOP_WORDS, true)) {
                continue;
            }
            $quoted[] = '"' . str_replace('"', '""', $token) . '"';
        }

        return $quoted === [] ? null : implode($operator, $quoted);
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS search_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS code_chunks (
                rowid INTEGER PRIMARY KEY,
                chunk_id TEXT NOT NULL UNIQUE,
                symbol_id TEXT NOT NULL,
                file_path TEXT NOT NULL,
                symbol_name TEXT NOT NULL,
                chunk_kind TEXT NOT NULL CHECK (
                    chunk_kind IN ('symbol_overview', 'method_body', 'function_body', 'method_fragment')
                ),
                start_line INTEGER NOT NULL,
                end_line INTEGER NOT NULL,
                source_sha256 TEXT NOT NULL,
                content_sha256 TEXT NOT NULL,
                signature TEXT NOT NULL,
                content TEXT NOT NULL
            )",
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_code_chunks_symbol ON code_chunks(symbol_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_code_chunks_file ON code_chunks(file_path)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_code_chunks_hash ON code_chunks(content_sha256)');
        $this->pdo->exec(
            "CREATE VIRTUAL TABLE IF NOT EXISTS code_chunks_fts USING fts5(
                symbol_name,
                signature,
                content,
                content='code_chunks',
                content_rowid='rowid'
            )",
        );
        $this->setMeta('schema_version', self::SCHEMA_VERSION);
    }
}
