# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Added

- Deterministic multi-file TOON map artifacts (`manifest.toon`, `files.toon`,
  `symbols.toon`, `relations.toon`, and `diagnostics.toon`) are now published
  by the existing `agent-map build` command alongside the legacy JSON index.
- Methods are projected as first-class symbol records in the canonical TOON
  store, with stable IDs and declaration relations for definitions, methods,
  inheritance, interfaces, and traits.
- Generated artifacts are hash-addressed through `manifest.toon`; the reader
  rejects missing, malformed, duplicate, or tampered records.

### Changed

- PHPStan `^2.2` is now a runtime dependency because it is the semantic engine
  for the next map-building phase. The structural parser remains in place so
  both analysis results can be reconciled rather than blindly trusted.

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
