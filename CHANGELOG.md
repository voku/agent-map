# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Added

- Add versioned, read-only `function_rename_plan` evidence for PHP functions using PHPStan-resolved function calls, exact source SHA-256/token/range edits, typed stale evidence, review-required dynamic calls, collision checks, and a fail-closed `function-rename-plan` CLI.

## 0.8.4 - 2026-08-22

### Added

- Publish method rename planning as an explicit versioned contract. Plans now carry complete map provenance (digest, effective backend, and analysis fingerprint) and expose stale source evidence separately from semantic blockers so hosts can fail closed and choose the correct recovery.
- Measure rename-plan provenance size on three real repositories before freezing the contract; the all-source hash projection consumed 80-88% of JSON output and was removed in favor of complete map identity plus per-edit mutation hashes.
- Document the read-only `rename-plan` evidence, status, observation, and mutation-host validation boundary.

### Changed

- Machine-readable method rename plans now use contract version `1.0`, collect evidence identity under `provenance`, and always publish typed `stale_evidence`. The pre-0.9 top-level backend and digest fields remain compatibility aliases; blocked plans continue to publish no edits.

## 0.8.3 - 2026-08-20

### Added

- Record the exact Composer package reference used by PHPStan-backed maps in `AnalysisFingerprint`; structural-only maps record `none`, while historical fingerprints without the field remain explicitly `unknown`.
- Record owner-level dogfood evidence for bounded Map navigation: three frozen real-issue replays
  compare a grep/read baseline, the projection `agent-loop` builds today, and agent-map's existing
  exact surfaces, under both the structural and PHPStan backends.
