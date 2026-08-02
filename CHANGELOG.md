# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Added

- PHPStan 2.2 semantic collection for resolved PHPDoc types, generics, call targets, instantiations, prototypes, and referenced types.
- Reconciliation between `voku/simple-php-code-parser` structural facts and PHPStan semantic facts, including explicit diagnostics and conflict states.
- `callers`, `callees`, and `context` commands for exact `Class::method` targets.
- Deterministic `EditContextPlan` generation with contracts, callers, caller tests, callees, type definitions, blind spots, source hashes, and bounded source slices.
- Optional TOON persistence for the same map model used by JSON.

### Changed

- The map schema is now 2.0 and records SHA-256 file hashes, semantic fingerprints, relations, and diagnostics.
- Methods and functions retain native, PHPDoc, and PHPStan-resolved types separately.
- JSON remains the default map format; TOON is selected explicitly with `build --format=toon`.
- PHPStan `^2.2` is a runtime dependency while `voku/simple-php-code-parser` remains the independent structural cross-check.
- Stale detection now uses SHA-256 content hashes instead of mtimes and SHA-1.

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
