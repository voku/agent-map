# AGENTS.md

## Repository role

`voku/agent-map` owns deterministic repository analysis and read-only navigation facts: declarations, source ranges, types, relations, reconciliation state, bounded architecture discovery, and typed edit/rename plans.

It is a source-backed mapping/planning layer. It does not own source mutation, workflow approval, prompt generation, validation execution, or durable Learning.

## Dependency direction

`agent-map` is below Recall and Loop. Preserve that direction.

- `agent-recall-compiler` and `agent-loop` may consume Map-owned typed APIs and plan contracts.
- Do not add runtime dependencies on those higher-level packages to decide workflow policy or mutation behavior.
- PHPStan is optional semantic enrichment. Structural-only operation must remain a supported explicit backend, not a silent fallback after a selected PHPStan backend fails.

## Invariants to preserve

- Mapping is read-only with respect to the analysed project.
- Every edit/rename plan is evidence, never authority to mutate. The mutation owner must validate provenance, source identity, blockers, review-required state, and staleness before writes.
- Conflicted or stale evidence must fail closed. Do not publish apparently safe edits when the planner is blocked.
- Dynamic/multiple-target facts stay visible as uncertainty; never collapse them into guessed certainty.
- Exact source ranges/hashes and backend identity are part of provenance. Do not weaken them to filenames or plausible symbol names.
- JSON and TOON are serializers of the same model, not separate semantic implementations.
- Keep unsupported refactorings explicit rather than claiming broad Rector parity.
- If adapting upstream implementation ideas/tests, preserve pinned provenance and required license attribution before substantial copied/adapted code lands.

## Implementation guidance

Prefer parser-/analysis-backed facts over heuristics. Keep query/planner results bounded and deterministic. New consumers should use typed public APIs instead of parsing CLI text or private map files.

When a feature starts requiring writes, lifecycle transitions, approvals, or execution, stop: that responsibility belongs to a higher owner such as `agent-loop`.

## Validation

Run:

```bash
composer ci
```

For changes touching rename/removal planning or optional semantic enrichment, also preserve the relevant repository dogfood workflows and structural-without-PHPStan behavior.

## Releases

Releases are marker-driven. `.release/<version>.json` must point to a release-ready ancestor commit whose own `CHANGELOG.md` contains the version. Existing tags are immutable.