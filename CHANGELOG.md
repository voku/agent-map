# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

## 0.8.8 - 2026-08-23

### Added

- Publish the versioned, read-only `class_constant_removal_plan@1.0` contract for provably unused private class constants with typed provenance, exact hash-bound deletion edits, blockers, review-required evidence, stale evidence, and explicit non-observable boundaries.
- Expand the shared removal-plan dogfood to prove safe class-constant deletion evidence and fail-closed observed-fetch handling alongside method and property removal.

### Changed

- Class-constant removal requires a current PHPStan-backed map, exactly one private single-constant declaration, no observed indexed static fetches, and a safe whole-node deletion range before publishing an edit.
- Reflection, `constant()`, dynamic constant names, inherited or late-static lookup, and source outside the indexed map remain explicit non-observable boundaries rather than being presented as detected evidence.

### Validation

- PR #45 passed the PHP 8.2-8.5 CI matrix, structural-without-PHPStan validation, temporal dogfood, rename-plan dogfood, expanded removal-plan dogfood, and external review on exact head `40e8ee8678dde9185ad7de99163a18209ab0152d` before squash merge to `f6f27e112d16ad96af7854a8ede3da5867d5500c`.

## 0.8.7 - 2026-08-23

### Added

- Publish the versioned, read-only `property_removal_plan@1.0` contract for provably unused private properties with typed provenance, exact hash-bound deletion edits, blockers, review-required evidence, stale evidence, and explicit non-observable boundaries.
- Add PHPStan-backed fail-closed property-removal planning plus expanded removal-plan dogfood across text, JSON, and TOON output.

### Changed

- Property removal is intentionally narrower than Rector: contract 1.0 requires zero observed semantic accesses and does not rewrite or remove write-only assignments.
- Static, promoted, multi-property, hooked, trait-sensitive, Doctrine-metadata-sensitive, stale, dynamically ambiguous, attribute/PHPDoc-sensitive, and unsafe-range cases fail closed or require explicit review instead of publishing an automatic deletion.

### Validation

- PR #44 passed the PHP 8.2-8.5 CI matrix, structural-without-PHPStan validation, rename-plan dogfood, expanded removal-plan dogfood, and external review on exact head `29dfcb86bc0bfa9e8b9cbc4fa81e9e24c855ed1f` before squash merge to `2e9e83902b4812e2ba36836a7a8650e38e514f9a`.

## 0.8.6 - 2026-08-23

### Added

- Publish the versioned, read-only `method_removal_plan@1.0` contract for unused private methods with typed provenance, exact hash-bound deletion edits, blockers, review-required evidence, stale evidence, and explicit non-observable boundaries.
- Add PHPStan-backed fail-closed removal planning plus dedicated removal-plan dogfood across text, JSON, and TOON output.

### Changed

- Method removal blocks observed calls, non-private contracts, traits, PHP magic methods, owner magic dispatch, reconciliation conflicts, unsafe compact/trailing-source layouts, unresolved class-string static calls across indexed PHP files, and stale evidence instead of publishing deletion edits.
- Explicit removal-plan `--index` paths follow the configured project root, while text output preserves provenance, stale evidence, and observability limits.

### Validation

- PR #42 passed the PHP 8.2-8.5 CI matrix, structural-without-PHPStan validation, temporal dogfood, rename-plan dogfood, dedicated removal-plan dogfood, and external review on exact head `c21f1b80b9abca508a672757d44ac11e26fffc2a` before squash merge to `02adb3358eac3a1efbcd43111b541d82da455c88`.

## 0.8.5 - 2026-08-23

### Added

- Publish versioned, read-only rename plans for functions, private properties, same-namespace classes, and class constants, each with exact preconditioned edits, provenance, stale evidence, blockers, review-required state, and explicit non-observable boundaries.
- Add PHPStan-backed semantic property declaration/fetch evidence, including nullsafe and inherited-property coverage, for governed private-property rename planning.
- Register the complete rename-plan family through shared capability discovery and dogfood exact edits, class moves, fail-closed evidence, and parser-valid rewritten PHP in isolated fixtures.
- Add Rector-inspired rename parity/provenance work without a Rector runtime dependency, retaining the pinned upstream source commit and MIT attribution in `THIRD_PARTY_NOTICES.md`.

### Changed

- Class rename plans may publish preconditioned same-directory file moves; mutation hosts remain responsible for validating and applying all edits and moves transactionally.
- Class-constant planning fails closed on stale maps, declaration collisions, ambiguous or inherited owners, late-static lookup, dynamic names/owners, and other evidence it cannot prove exactly.

### Validation

- The released rename capabilities passed the repository PHP 8.2-8.5 CI matrix, structural-without-PHPStan validation, temporal dogfood, shared rename-plan dogfood, and external review on their accepted heads.

## 0.8.4 - 2026-08-22

### Added

- Publish method rename planning as an explicit versioned contract. Plans now carry complete map provenance (map digest, effective backend, and analysis fingerprint) and expose stale source evidence separately from semantic blockers so hosts can fail closed and choose the correct recovery.
- Measure rename-plan provenance size on three real repositories before freezing the contract; the all-source hash projection consumed 80-88% of JSON output and was removed in favor of complete map identity plus per-edit mutation hashes.
- Document the read-only `rename-plan` evidence, status, observation, and mutation-host validation boundary.

### Changed

- Machine-readable method rename plans now use contract version `1.0`, collect evidence identity under `provenance`, and always publish typed `stale_evidence`. The pre-0.9 top-level backend and digest fields remain compatibility aliases; blocked plans continue to publish no edits.

## 0.8.3 - 2026-08-20

### Added

- Record the exact Composer package reference used by PHPStan-backed maps in `AnalysisFingerprint`; structural-only maps record `none`, while historical fingerprints without the field remain explicitly `unknown`.
- Record owner-level dogfood evidence for bounded Map navigation: three frozen real-issue replays
  compare a grep/read baseline, the projection `agent-loop` builds today, and agent-map's existing
  exact surfaces, under both the structural and PHPStan backends
  (`docs/dogfood/map-navigation-evidence.md`, harness and raw reports in `tools/dogfood/`).
- Classify the Map surface by measured role - product path, expert/diagnostic, unproven - and record
  which capabilities currently pay rent, which need their own replay before anyone claims they do,
  and which are subtraction candidates.
- Fail closed on dogfood replay backend mismatch: a replay publishes evidence only when the built
  map's own effective backend proves the backend that was requested, and every report records
  agent-map's `AnalysisFingerprint` so PHPStan provenance no longer has to be reconstructed later.
- Reject contradictory replay reports at the summary boundary instead of rendering them as rows.

### Fixed

- Restore the replay harness's strategy, projection and reporting half, which was lost when #26 was
  merged; the committed measurements regenerate byte-identically again.

### Changed

- No product behaviour changed. The evidence is deliberately separate from any change it may later
  justify, and measurement policy, budgets, ranking and grading are untouched.

### Validation

- PR #27 passed the full PHP 8.2-8.5 CI matrix, temporal dogfood, structural-without-PHPStan validation, and external review before merge.
- PR #28 passed the same PHP 8.2-8.5 CI matrix, temporal dogfood, the explicit `composer install --no-dev` structural build, and CodeRabbit with no actionable comments.

## 0.8.2 - 2026-08-18

### Added

- Add explicit semantic-backend selection so embedders can choose `auto`, `structural`, or `phpstan` without treating PHPStan availability as an implicit install-time requirement.
- Preserve structural map generation as a supported capability when semantic PHPStan analysis is unavailable or intentionally not selected.

### Changed

- Backend identity is now explicit owner data that lifecycle hosts can consume instead of re-detecting semantic capability themselves.

### Validation

- PR #23 passed PHPUnit/PHPStan CI on PHP 8.2, 8.3, 8.4, and 8.5 on its exact head before merge.
## 0.8.1 - 2026-08-15

### Added

- Added immutable `MapReadiness` and `MapReadinessInspector` owner boundaries so
  embedders can consume current map freshness, source snapshot identity, and derived
  Search compatibility without reconstructing agent-map policy from artifact presence.
- Search readiness is inspected read-only and distinguishes unavailable, missing,
  invalid, stale, and current state without rebuilding, repairing, migrating, or
  creating Search state.

### Validation

- Regression coverage proves missing, invalid, stale, fingerprint-less, and current map
  states; missing, corrupt, mismatched, and current Search state; and that inspection
  does not mutate or create the Search database.
- `composer ci` is green on PHP 8.2, 8.3, 8.4, and 8.5, and the temporal self-dogfood
  job is green for the exact implementation candidate.

## 0.8.0 - 2026-08-14

### Added

- Added `MapArtifactPaths` as the single functional owner of the JSON/TOON map,
  Search database, temporal-history database, structural cache, and PHPStan cache
  filenames. Embedders choose only the artifact mount point; agent-map owns the
  complete tree below it.
- Added `CliApplication` as the public routing boundary shared by standalone and
  embedded callers. Temporal, Discovery, and general commands now receive the same
  artifact owner without command-specific argv rewriting.
- Added bounded `file_body` Search chunks for mapped PHP files with no declarations,
  with a schema-1.1 migration that preserves existing Search rows and rowids.

### Changed

- Relative `--out`, `--index`, and `--database` values now resolve consistently against
  `--root`; absolute artifact paths remain unchanged.
- Standalone generated state remains below `.agent-map/`. The unreleased intermediate
  `.agent-loop/map/` default is not preserved; embedding applications may instead mount
  the complete artifact tree wherever their own workspace layout requires.
- Structural and PHPStan caches now follow the same artifact owner as the map, Search,
  and temporal-history files.
- Explicit empty long-option values such as `--out=` are rejected instead of silently
  suppressing default resolution. This applies consistently to value-taking long options.
- The shipped Makefile defers the default map filename to the CLI while retaining
  `AGENT_MAP_INDEX` as an explicit override when a consumer supplies one.
- Added Renovate configuration for consistent hosted dependency-update processing.

### Fixed

- A literal positional argument named `help` no longer causes normal command output to
  receive an appended CLI help section.
- Mapped PHP data/configuration files without declarations are now searchable instead of
  being deterministically invisible to the derived Search index.
- Embedded relative artifact roots are resolved against the embedding project root and
  are not relocated by a later explicit command `--root` source override.
- Standalone `history observe` derives its default history database from the loaded map's
  project root, while an injected artifact root remains authoritative for embedded use.

### Validation

- Regression coverage includes root-relative and absolute artifact options, positional
  help output, symbol-less file-body Search chunks and schema migration, shared embedded
  routing, cache placement, and embedded-map-root precedence over explicit source roots.
- Release remains gated on `composer ci` across PHP 8.2, 8.3, 8.4, and 8.5 plus the real
  temporal self-dogfood job.

## 0.7.0 - 2026-08-11

### Added

- Added `agent-map history diff` for deterministic structural comparison of two
  canonical maps, including file, symbol, method, and semantic-relation lifecycle facts
  with explicit before/after evidence while ignoring line-number-only movement.
- Added `agent-map history coupling` to compare bounded Git co-change evidence with the
  current semantic/path coupling graph. Repeated pairs retain the concrete Git revisions
  that produced the evidence and bulk commits are skipped by default.
- Added rebuildable `.agent-map/history.sqlite` snapshots with `history observe` and
  `history show`, retaining compact declaration, architecture-region, caller/callee,
  and dependency observations without storing source text or embeddings.
- Added `history claims` with the deliberately narrow `hidden_temporal_coupling`
  heuristic for strong repeated co-change without a current semantic graph edge. Claims
  are explicitly heuristic and preserve thresholds, raw evidence, and supporting commits.
- Added temporal-evolution documentation and ADR 0002 defining Git as the authoritative
  historical source and SQLite history as a derived projection.

### Changed

- Temporal snapshot order is defined by recorded snapshot sequence rather than Git
  timestamps, which are retained only as metadata because rebases, cherry-picks, and
  synthetic merges do not provide a reliable ordering relation.
- Temporal text output now preserves commit provenance instead of dropping evidence that
  remains present in JSON/TOON.
- CI cancels superseded runs on the same ref, a process issue exposed while dogfooding
  connector-driven incremental commits.

### Validation

- Real self-dogfood on agent-map itself observed 786 events with 767 structural events,
  48 repeated Git co-change pairs with 26 lacking a semantic static edge, 21 explicit
  evidence-backed claims, two revision snapshots, and 718 current entity observations.
- Self-dogfood caught and fixed an invalid timestamp-ordering assumption before release,
  and adversarial review added commit-level provenance plus side-effect-free history reads.
- Release remains gated on `composer ci` across PHP 8.2, 8.3, 8.4, and 8.5 plus the real
  temporal self-dogfood job.

## 0.6.0 - 2026-08-11

### Added

- Added deterministic PHP architecture regions derived from the persisted semantic map.
  Weighted file coupling combines PHP-specific relations with a weak directory prior,
  then performs deterministic multi-level community detection to infer modules,
  subsystems, and systems without requiring a namespace convention.
- Added region evidence including internal/external coupling, boundary ratio and
  strength, internal density, cross-cut score, namespace agreement, directory
  agreement, interface files, and dominant semantic signals instead of hiding the
  inference behind one opaque confidence score.
- Added cross-cutting file detection and down-weighting so high-degree utility or
  infrastructure files do not collapse otherwise coherent regions into one graph.
- Added `agent-map discover --region LABEL|ID` for bounded drill-down into an inferred
  region using the architecture map as the navigation coordinate instead of guessing
  repository paths first.
- Added architecture-aware impact projection. The existing bounded impact traversal
  remains the source of node-level evidence and uncertainty while affected nodes are
  additionally grouped by inferred architecture region.

### Changed

- `agent-map discover` now leads with the inferred architecture hierarchy before raw
  hubs and coupling statistics so an agent can orient itself before selecting symbols.
- Architecture output is deterministic for a given map digest, including stable region
  identities and tie-breaking.
- Region labels use namespace evidence when useful, then directory structure and file
  tokens; labels are globally disambiguated so label-based drill-down remains usable.
- Structured region output includes the selected root-to-region path, matching the text
  representation.
- Impact text output preserves relation kinds, evidence IDs, and via-node IDs rather
  than dropping provenance that remains present in JSON/TOON.

### Fixed

- Fixed singleton absorption so live community sizes are respected while assignments
  change, preventing absorbed files from being stranded in communities that later
  disappear from the region map.
- Fixed parent/subsystem drill-down paths so they end at the region the user selected
  instead of an arbitrary finest descendant containing the same file.
- Exact region IDs and labels now take precedence over ID-prefix matches.
- Root-level PHP files no longer infer `.` as a region label.
- Cross-cut detection now requires a degree outlier above the median instead of marking
  every member of a regular graph as high-degree.
- Preserved the public 0.5 `RepositoryDiscoveryReport` constructor while making the new
  architecture result additive.

### Validation

- Added real self-dogfood coverage that maps agent-map's own Discovery, Index, and Search
  sources, requires multiple useful regions, no unassigned selected files, deterministic
  output, and resolvable architecture paths.
- Added regression tests for review findings covering singleton absorption, region-path
  selection, label collisions, exact selectors, root-level labels, cross-cut evidence,
  structured CLI output, and architecture-grouped impact.
- Release remains gated on `composer ci` across PHP 8.2, 8.3, 8.4, and 8.5.

## 0.5.0 - 2026-08-10

### Added

- Added evidence-backed architecture discovery with `agent-map discover`. It derives
  entrypoint candidates, call hubs, orchestrators, type dependency hubs, namespace
  coupling, directory coupling, file coupling, and relation-quality counts directly
  from the persisted map without requiring an LLM or a search query.
- Added `agent-map rank` with deterministic one-hop graph metrics for `dependents`,
  `callers`, `dependencies`, `callees`, and `members`. Scores count unique neighbours
  rather than raw relation rows, so repeated call sites do not inflate importance.
- Added bounded reverse dependency analysis with `agent-map impact Class::method`.
  Results retain relation evidence, `via_node_ids`, depth, truncation state, and
  uncertainty instead of returning an opaque blast-radius score.
- Added path-based architecture signals independently from namespaces. Namespace-less
  and legacy PHP projects can now derive meaningful directory and file coupling from
  repository-relative source paths instead of collapsing into one global namespace.
- Added indexed graph adjacency for discovery/ranking/traversal so graph-oriented reads
  operate in O(V+E) preparation rather than repeatedly scanning all relations per node.
- Added dedicated architecture-discovery documentation in
  `docs/architecture-discovery.md`.

### Changed

- `dynamic` and `multiple_targets` relations remain explicit uncertainty throughout
  impact traversal. Uncertainty propagates transitively through a path; when the same
  node is independently reachable through a fully resolved path, that certain path is
  sufficient evidence for the impact while the ambiguous path remains represented by
  its evidence.
- Architecture discovery treats namespaces as one signal rather than the architecture
  model. Namespace, directory, and file coupling are reported independently.
- Discovery commands reject stale maps instead of combining current source with old
  graph evidence.
- Repository paths are normalized before directory grouping so path-based discovery is
  not dependent on the host operating system's separator convention.

### Fixed

- Corrected an early graph-ranking implementation that would have repeatedly scanned
  the complete relation set for every candidate on large repositories.
- Corrected impact semantics so descendants of uncertain edges cannot silently become
  certain again merely because they are more than one hop away.
- Corrected impact aggregation so an additional uncertain path does not downgrade a
  node that is also independently proven by a certain path.

### Validation

- Extended the existing package self-dogfood test so `agent-map` builds a real map of
  its own builder/test slice and then runs `ArchitectureDiscovery` against it.
- Added focused tests for graph ranking, bounded impact traversal, transitive
  uncertainty, certain-vs-uncertain alternate paths, architecture discovery, and
  namespace-less PHP structure.
- `composer ci` is green on PHP 8.2, 8.3, 8.4, and 8.5.

## 0.4.1 - 2026-08-06

### Changed

- Stop words now come from `voku/stop-words` at runtime instead of a 1 335-entry
  copy in `QueryPlanner`. Of that inlined list, 1 324 entries were the package's
  own English and German data; only 11 were genuinely additional code-domain
  words (`class`, `method`, `function`, `file`, `code`, `use`, `used`, `handle`,
  `handled`, `work`, `works`), and those are all that remain in source.
- Measured before deciding: loading English and German costs ~2 ms once per
  process and ~0.002 ms per later call, because the package already caches its
  per-language data behind a `require` of a PHP array. There is nothing for a
  disk cache to save, so none was added.
- The lookup, not the loading, was the cost. `in_array()` over ~1 300 words is a
  linear scan run on every token of every indexed chunk: 1.4M lookups took
  4 227 ms that way against 459 ms through the new `StopWordIndex` hash map
  (17.8 ms for the raw `isset()`, the remainder being the `mb_strtolower()` both
  paths do).

- The 11 code-domain words that are *not* in `voku/stop-words` (`class`, `method`,
  `function`, `file`, `code`, `use`, `used`, `handle`, `handled`, `work`, `works`)
  gained their four German counterparts (`klasse`, `methode`, `funktion`,
  `datei`). German capitalizes every noun, so a code-domain noun in a German
  question is identifier-shaped by every structural rule there is: before this,
  "Welche Methode schreibt X" claimed `Methode` was a symbol the user had named.

### Fixed

- The binary resolved its autoloader by preferring the package's own `vendor/`
  directory. When one is present next to an installed copy - a path repository, a
  mirrored checkout, a stale local install - that autoloader wins and silently
  loads *its* dependencies instead of the project's. Found by a release-set smoke
  test that reported `Undefined property Session::$ephemeral` against an
  installed version that plainly had it. The outer autoloader is now tried first.

- The query planner tokenized with ASCII letter classes, which does not reject a
  non-ASCII word but *truncates* it: "Berechtigungsanträge" reached the
  structural channel as `Berechtigungsantr`, "Zugriffsprüfung" as `Zugriffspr`,
  and "Größe" as `Gr` - short, capitalized, and therefore identifier-shaped, so
  a mangled fragment was promoted to "the symbol you named". Letter and digit
  classes are now Unicode throughout the planner, which also matches PHP, whose
  identifiers may legally contain bytes above 0x7F.
- `SearchIndexTest::testShortIdentifiersAndStopWordsInQueryPlannerAndSearchStore`
  asserted that `searchLexical('Accounting für D3?')` returns hits against a
  fixture containing only `RetryHandler` and `Mailer`, and failed since it was
  added. It now asserts what the design actually promises: a query the corpus
  cannot answer returns nothing, and a German stop word inside a query does not
  change which chunks rank.

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

- `agent-map refresh` re-analyses only the files whose hash moved plus the ones that appeared since the last build, and patches them into the existing index;
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
- JSON remains the default interoperable storage format; TOON is selected explicitly with `build --format=toon`.
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
