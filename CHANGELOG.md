# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.11.6 - 2026-09-09

### Changed

- `scope` and `context` bring a stale index current before answering, when that is safe. A stale index used to end the conversation: `scope` threw `Agent map is stale. Rebuild it before inspecting a scope.` with no index path, no stale count and no command, so the host had to remember `stale` -> `refresh` -> re-issue the original question. Editing an unrelated Markdown file was enough to trigger it, and a tool that answers a maintenance errand instead of the question loses to text search, which has no freshness concept and always answers something. Repair is deliberately narrow: it runs only when the index and the current run resolve the same backend, which is the guard `refresh` already applies before merging, so PHPStan evidence is never quietly downgraded to structural-only; and it builds against the scope the index recorded rather than the files that happened to change, so a repair cannot shrink indexed coverage to whatever was edited last. Currentness means the same thing a `refresh` means by it: source hashes, and for a PHPStan-backed index also its configuration and `composer.lock`, because those move while every indexed file's hash stays identical and the callers and types a read reports would otherwise belong to the previous observation envelope. The repair always uses the scope the index recorded and never this command's `--paths`, `--exclude` or `--scan`: those flags exist so `build` and `refresh` can change what is observed, and a question must not smuggle a reconfiguration into persistent derived state. A repair that fails reports why - a parse failure, a PHPStan failure and an I/O failure are different problems - instead of collapsing every fault into the same maintenance errand. It is never silent - the note goes to STDERR before the work starts, because a PHPStan-backed repair re-analyses the recorded semantic scope and can take tens of seconds. That is the same work a manual `refresh` would do, so the cost is unchanged; only the round trip disappears. Anything the repair cannot do safely still fails closed, and now names the index it judged, how many files are stale, and the exact command to run. Reported as #86.

### Fixed

- `refresh --index=<path>` writes the refreshed map back to that index. It resolved its output to the artifact root's default filename instead, so a project whose index is not named `php-symbols.json` had every refresh written to a second file while the named index stayed stale: the command reported `Refreshed 1 changed ... file(s)` and the next run read the same unchanged source again. An explicit `--out` still wins, and a `.toon` index keeps the TOON serializer without being told twice.

### Added

- `AgentMapBuilder::backend()` reports the backend identity a build by that instance would write, so a caller can test an index for mergeability before attempting an incremental merge rather than reading it out of the exception afterwards.
- A refresh that cannot merge two semantic backends now refuses with the full build command - root, paths, output and, for a structural-only index, `--backend=structural` - instead of prose describing that a full build is needed. The refusal already knew every argument; a host following the prescribed next action literally would otherwise loop `refresh` -> refusal -> `refresh` forever. The command rebuilds the scope the index recorded rather than the wider search scope a refresh uses, keeps a structural-only index structural-only, and quotes any value that would not survive being copied into a shell. Reported as `voku/agent-loop#404`.

## 0.11.5 - 2026-09-09

### Added

- `ImpactAnalyzer::forFile()` answers what can notice a change to one indexed file, returning a typed `FileImpactReport`. Impact was reachable only from a single method or node id, so a consumer holding a path - a Contract scope entry, a changed file - had to pick one declaration out of the file and accept a narrower answer, or union several traversals itself and get the shared bound and the uncertainty composition wrong. Every declaration in the file seeds one traversal, the node bound applies to their union, and the file's own declarations are excluded because changing a file is not something that file notices.

## 0.11.4 - 2026-09-09

### Fixed

- Declare `nikic/php-parser:^5.0` as a direct runtime dependency because agent-map production code directly consumes php-parser 5 APIs. Lowest-dependency consumers can no longer resolve php-parser 4.x and then type-error in class move/rename planning despite satisfying the published Composer metadata.

### Validation

- Add a dedicated `--prefer-lowest` dependency-floor check that asserts php-parser 5.x and exercises the class-move and class-rename planner regressions. Exact-head PHP 8.2, 8.3, 8.4 and 8.5 `composer ci`, structural/temporal checks, and rename/removal/class-move dogfood are green.

## 0.11.3 - 2026-09-09

### Fixed

- Make incremental `search-index refresh` preserve the same deterministic duplicate canonical-chunk winner as a clean full build. When a refresh introduces an earlier path while a later duplicate claimant remains in the existing index, the retained row no longer wins merely because it survived the partial delete. Refresh now compares retained and incoming paths using the existing path-ordered first-wins rule, removes a later retained claimant together with its external FTS row inside the existing transaction when necessary, and otherwise skips the later incoming duplicate. The one-sided refresh regression verifies identical searchable results and `chunk_id@content_sha256` state against a clean rebuild while preserving deleted-path pruning, transaction rollback, schema, and chunk-id semantics.

## 0.11.2 - 2026-09-08

### Performance

- Cache parsed `AgentMapIndex` instances in `IndexReader` across repetitive queries within a process when mtime and filesize are unchanged, avoiding duplicate JSON decoding and object hydration.
- In `IndexReader::read()`, pre-filter unneeded top-level sections (such as `diagnostics`, `local_bindings`, `local_exits`) before invoking `AgentMapIndex::fromArray()`, avoiding construction of thousands of diagnostic and entry objects during partial section reads.
- Clear `IndexReader` cache and PHP stat cache in `IndexWriter::write()` to maintain immediate read-after-write consistency.

## 0.11.1 - 2026-09-08

### Fixed

- Stop `search-index refresh` aborting on a canonical symbol id that is declared more than once in the repository. A full build deletes every row before inserting, so the only duplicates it can meet are inside its own batch, which it resolves by keeping the first and reporting a skipped count. A refresh deletes only the paths it replaces, so a row belonging to an untouched file could still hold a chunk id the incoming batch was about to insert, and the whole transaction died on `UNIQUE constraint failed: code_chunks.chunk_id`. Repositories with any duplicated canonical symbol id - legacy duplication, fixtures, vendored copies - could therefore build a search index but never refresh one. `replaceChunks()` now seeds its seen set from the ids that survived the delete, so a refresh skips exactly what a build skips.

### Added

- Add `SearchIndexStore::semanticProvider()`, the typed way to obtain the embedding provider an index's vectors were actually written with, and `SearchIndexStore::storeEmbeddingState()` to record it. Restoration was previously implemented only in a private CLI method reading the store's own metadata keys, so an embedding host that wanted the semantic channel had to reproduce which key holds the fitted weighting, how it is shaped, and what makes it valid - a second definition of the vector space living outside the package that owns it. The factory refuses rather than refits: it returns `null` when sqlite-vec is unavailable, when nothing is embedded, when the recorded state is unusable, or when the restored model no longer matches the fingerprint the stored vectors belong to. The CLI and the navigation-replay dogfood are now callers of it rather than second copies of it.

### Changed

- Support skipping companion relation decoding in `MapReadinessInspector::inspect(MapArtifactPaths $artifacts, bool $loadRelations = true)` so callers inspecting map file freshness can avoid parsing relations payloads.

## 0.11.0 - 2026-09-07

### Added

- Add local semantic understanding inside methods and functions (#61):
  - Flow-sensitive `LocalSemanticFrame` capturing ordered execution checkpoints (`LocalBindingCheckpoint`, `LocalGuardCheckpoint`, `LocalUseCheckpoint`, `LocalExitCheckpoint`).
  - Byte-exact source coordinates (`startFilePos`, `endFilePos`) across relations, variable bindings, and exit statements.
  - PHPStan collectors for assignments (`Assign`, `AssignOp`, `AssignRef`, `Foreach_`, `Catch_`) and exit points (`Return_`, `Throw_`).
  - Index schema 2.0 extension persisting `local_bindings` and `local_exits` in companion relations file with backward compatibility.
  - Flow reconstruction via `LocalSemanticFrameBuilder` extracting variable narrowing (e.g. `excludes false`, `excludes null`, `instanceof`), semantic method/property uses on locals, and exit reasons.
  - Integrated into `ScopeInspector`, `ScopeInspection`, `EditContextPlanner`, `EditContextPlan`, and CLI `scope` command output.
- Unify machine-readable envelope structure across all fourteen governed plan contracts: add explicit `target_id` property and output key to `MethodMovePlan`, `MethodCopyPlan`, `MethodScaffoldPlan`, `ClassScaffoldPlan` and their planners.
- Pin envelope invariants across all 14 governed plan contracts across all 5 families (`rename`, `removal`, `move`, `copy`, `scaffold`) in `tests/PlanContractShapeTest.php`.
- Complete pre-1.0 stability policy and public surface classification in `docs/stability.md` classifying all 14 plan contracts as stable at contract version 1.0 with exit code 1 on blocked.
- Require released `voku/agent-graph` `^0.2.0` and consume graph relation contracts and SQLite runtime (#63, #65, #71, #73).

## 0.10.0 - 2026-09-04

### Fixed

- Reading a split index whose relations companion is missing now fails loudly
  instead of returning an empty relation graph. An incomplete copy was
  indistinguishable from a map with no relations, so `map history diff` reported
  every relation on the other side as newly added against an unchanged tree. A
  renamed symbols file beside the companion it names now resolves it; legacy
  single-file indexes are unaffected.

### Changed

- Move package Make include from root `Makefile.agent-map.mk` to `resources/make/agent-map.mk`, and move `THIRD_PARTY_NOTICES.md` to `docs/reference/third-party-notices.md` following the shared repository layout convention. Introduce `PackageResources` as the single owner of package-shipped asset paths.
- Split the persisted index into primary symbol definitions (`php-symbols.json` or `.toon`) and a companion relations file (`php-relations.json` or `.toon`). This decouples large call/override relation graphs (often 80-90% of index size) from core symbol definitions, enabling direct fast reads (~60ms vs 1.4s) for file, symbol, stats, and changed lookups while preserving full relations on-demand or transparently via `IndexReader::read()`.
- Optimize `changed` and `stats` CLI commands to use `IndexReader::readSections(['files'])` instead of a full `read()`. This skips decoding >70MB (87% of the index payload) of relations data when only file hashes, file metadata, and symbol counts are needed, reducing command execution time from >1.1s to ~0.4s and significantly lowering peak memory usage on large indexes.

## 0.9.0 - 2026-08-31

### Added

- Add typed consumer accessors to `MapArtifactPaths` for the canonical index, relations, graph, search, temporal, source-cache, and lock artifacts.

### Changed

- Move the graph runtime behind `GraphIndexStore` so graph-specific storage and validation details remain owner-owned.

## 0.8.0 - 2026-08-29

### Added

- Add graph-backed impact traversal and source materialization contracts.

## 0.7.0 - 2026-08-26

### Added

- Add typed map/search projection contracts for downstream consumers.

## 0.6.0 - 2026-08-23

### Added

- Add incremental map freshness and changed-path inspection support.

## 0.5.0 - 2026-08-15

### Added

- Add persisted code-search chunks, lexical search, and optional semantic vector projection.

## 0.4.1 - 2026-08-06

### Changed

- Load stop words from `voku/stop-words` instead of copying the package data into source. The package already caches its per-language arrays in process; keeping a second 1,300-word source constant added maintenance cost without avoiding runtime work. The code-domain additions remain local and lookups use a hash set rather than a linear `in_array()` scan.

## 0.4.0 - 2026-08-05

### Added

- Add the initial hybrid search-index surface with canonical chunk identities and FTS-backed lexical search.
