# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.4.0 - 2026-08-05

### Added

- Multi-process parallel chunk extraction in `ChunkExtractor` using `pcntl_fork` and UNIX domain sockets when processing indices with over 50 files, with automatic CPU core count detection.
- `SearchParallelChunkExtractorTest` test suite covering parallel extraction equality, fingerprint consistency, skipped path preservation, and edge cases.
- Hybrid search derived index (`.agent-map/search.sqlite`) with `agent-map search-index build|refresh|doctor` and `agent-map search` CLI commands.
- `sqlite-vec` vector retrieval channel integration with `CorpusEmbeddingProvider` (TF-IDF + feature hashing).
- Retrieval benchmarking via `agent-map search benchmark` evaluating Recall@5 and MRR@10 across search failure categories.

### Changed

- Cached token placement (`[bucket, sign]`) in `CorpusEmbeddingProvider` to avoid repeated SHA-256 digests during vector generation, and unified tokenization into a single regex pass.
- Made search index updates incremental, syncing `.agent-map/search.sqlite` with map changes.
- Restricted structural channel matches to exact symbol identifiers to prevent structural noise from outranking lexical hits.

### Fixed

- Handled duplicate canonical symbol IDs across files gracefully during search index creation without throwing UNIQUE constraint errors.

## 0.3.0 - 2026-08-04

### Added

- `agent-map refresh` re-analyses only the files whose hash moved plus the ones
  that appeared since the last build, and patches them into the existing index;
  deleted files drop out. `agent-map build --merge` does the same for an
  explicit `--paths` scope. Keeping a large map current no longer means paying
  for a full rebuild.
- `--scan=<dirs>` maps to PHPStan's `scanDirectories`, so a scope that
  references classes living outside it resolves their types instead of failing
  with "Class X was not found ... discovering symbols is probably not
  configured properly".

### Changed

- Symbol extraction now suspends every non-Composer autoloader while parsing.
  `voku/simple-php-code-parser` resolves `{@inheritdoc}` parents through
  `class_exists($parent, true)`, so in a project whose autoloader maps class
  names onto procedural legacy files, "parse one file" used to `include` and
  *execute* that parent - rendering pages, opening database connections - and
  then indexed the reflected parent as a symbol of the child file. On a real
  code base that accounted for roughly half of all `declares_method` relations
  and 19 000 bogus `structural_only_method` diagnostics.
- The PHPStan child process receives the scope directories instead of the
  expanded file list whenever no `--exclude` is set. PHPStan disables its
  result cache completely when only files are passed ("Result cache not used
  because only files were passed as analysed paths"), which made every rebuild
  a cold rebuild; a no-change rebuild of a 3 300-file project dropped from over
  15 minutes to under a minute.
- `IndexWriter` starts every top-level section on its own line (the output is
  still ordinary JSON), and `IndexReader::readSections()` decodes only the
  sections a command needs. `file`, `query`, `stale`, and `summary` now skip
  the relation list - the largest section by far - which took their runtime on
  a 66 MB index from ~6.7 s to ~2 s at 75 MB instead of >1 GB peak memory.
- `SemanticAnalyzer::analyse()` takes two additional optional parameters
  (`$analyseDirectories`, `$scanDirectories`). Custom implementations must
  extend their signature.

## 0.2.1 - 2026-08-04

- Added `--phpstan-memory-limit` to `agent-map build`, forwarding the bounded
  value to the PHPStan semantic-export child process.
- Added bounded literal focus windows to `EditContextPolicy`, allowing
  consumers to send a surgical primary-source excerpt instead of a large
  method while retaining the full target if no literal matches.
- Replaced the package-specific dogfood fixture with package-owned self-dogfood
  coverage.

## 0.2.0 - 2026-08-03

### Added

- PHPStan 2.2 semantic collection for resolved PHPDoc types, generics, call targets, instantiations, prototypes, and referenced types.
- Reconciliation between `voku/simple-php-code-parser` structural facts and PHPStan semantic facts, including explicit diagnostics and conflict states.
- `callers`, `callees`, and `context` commands for exact `Class::method` targets.
- `scope` now enriches syntax-derived calls with persisted PHPStan targets, resolution states, and inferred receiver/result types while retaining AST-only SQL-table and template hints.
- Deterministic `EditContextPlan` generation with contracts, callers, caller tests, callees, type definitions, blind spots, source hashes, and bounded source slices.
- Optional TOON persistence for the same map model used by JSON.

### Changed

- The map schema is now 2.0 and records SHA-256 file hashes, semantic fingerprints, relations, and diagnostics.
- Methods and functions retain native, PHPDoc, and PHPStan-resolved types separately.
- JSON remains the default map format; TOON is selected explicitly with `build --format=toon`.
- PHPStan `^2.2` is a runtime dependency while `voku/simple-php-code-parser` remains the independent structural cross-check.
- Stale detection now uses SHA-256 content hashes instead of mtimes and SHA-1.
- `scope` rejects stale maps rather than combining current source with outdated semantic ranges.

### Fixed

- Hardened PHPStan subprocess execution, function and nullsafe-call collection, relation reconciliation, override traversal, source slicing, test-path detection, and context-budget handling after review.

## 0.1.1 - 2026-07-16

### Changed

- `related`'s `likely_tests`, `same_namespace`, and `mentions` sections now
  render as bare file paths (with an "N more symbol(s)" count when
  applicable) instead of a full per-file symbol/method dump. Those sections
  are context around the query, not the answer to it, and dumping full
  detail on top of `primary` (which keeps it) made a default `related` call
  far larger than a focused `query` for the same term. `primary`'s detail
  level is unchanged.

## 0.1.0 - 2026-07-13

### Added

- Initial `agent-map` CLI: builds a compact JSON symbol index of a PHP
  repository and answers `related`, `file`, and `changed` queries.
- SIGINT/SIGTERM handling in `MagoAstBackend::parseMany` to terminate
  in-flight `mago` child processes on interrupt.

### Changed (before first release)

- Replaced the `mago`/token-scanner AST backends with a single extractor built
  on `voku/simple-php-code-parser` (nikic/php-parser under the hood), removing
  the separate parallel-process backend, token backend, and their scanner
  hacks in favor of one in-process parse per file.
- Bumped `voku/simple-php-code-parser` to `^0.22`, which adds native
  `endLine`/`traitUses` to its model, replacing the token-scan pass this
  project previously used to backfill closing-brace lines and trait `use`
  names.
- `query` now combines literal and separator-normalized hits, ranks them, and
  limits method-only hits to the matched method. `related` now derives likely
  tests and namespace peers from several top source candidates instead of only
  the first result.

### Removed (before first release)

- `src/Backend/*` (`AstBackend`, `AstResult`, `MagoAstBackend`,
  `ParallelAstBackend`, `TokenAstBackend`) and
  `src/Extract/PhpTokenSymbolExtractor.php`.
