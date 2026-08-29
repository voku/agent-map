# AGENTS.md

## Repository role

`voku/agent-map` owns deterministic repository analysis and read-only navigation facts: declarations, source ranges, types, relations, reconciliation state, bounded architecture discovery, and typed edit/rename plans.

It is a source-backed mapping/planning layer. It does not own source mutation, workflow approval, prompt generation, validation execution, durable Learning, or deciding which refactoring a coding agent should perform.

For an already-known coding intent, Map should make the mechanical repository work cheaper and more exact than ad-hoc `grep`, broad file reads, or line-oriented replacement: resolve the requested code identity, expose the smallest relevant source/relations, and publish a fail-closed plan when the requested transformation has a governed contract.

## Dependency direction

`agent-map` is below Recall and Loop. Preserve that direction.

- `agent-recall-compiler` and `agent-loop` may consume Map-owned typed APIs and plan contracts.
- Do not add runtime dependencies on those higher-level packages to decide workflow policy or mutation behavior.
- PHPStan is optional semantic enrichment. Structural-only operation must remain a supported explicit backend, not a silent fallback after a selected PHPStan backend fails.

## Invariants to preserve

- Mapping is read-only with respect to the analysed project.
- Coding intent belongs to the coding agent/host. Map may resolve and plan an explicitly requested change; it must not invent refactoring candidates, recommend architecture changes, or turn repository facts into autonomous work selection.
- Every edit/rename plan is evidence, never authority to mutate. The mutation owner must validate provenance, source identity, blockers, review-required state, and staleness before writes.
- Conflicted or stale evidence must fail closed. Do not publish apparently safe edits when the planner is blocked.
- Dynamic/multiple-target facts stay visible as uncertainty; never collapse them into guessed certainty.
- Exact source ranges/hashes and backend identity are part of provenance. Do not weaken them to filenames or plausible symbol names.
- JSON and TOON are serializers of the same model, not separate semantic implementations.
- Keep unsupported refactorings explicit rather than claiming broad Rector parity.
- If adapting upstream implementation ideas/tests, preserve pinned provenance and required license attribution before substantial copied/adapted code lands.

## Implementation guidance

Prefer parser-/analysis-backed facts over heuristics. Keep query/planner results bounded and deterministic. New consumers should use typed public APIs instead of parsing CLI text or private map files.

For coding-agent navigation, reuse the narrowest existing owner surface before adding another command:

1. When the PHP target is already known, resolve it exactly through `scope` / `ScopeSelector`; do not route the agent back through fuzzy discovery.
2. For an intended method edit, use `context` / `EditContextPlanner` to obtain the bounded primary source plus contracts, change candidates, dependencies, verification slices, blind spots, and omissions.
3. Ask `callers` or `callees` only when that exact relation is needed beyond the bounded context result.
4. Use `query`, `related`, or ranked Search when the target is not yet known. Literal/config/template/file-name questions that Map cannot model remain native text-search shapes.
5. For a requested rename/removal/move, extend or reuse a dedicated typed plan contract that accepts the explicit target and requested result. Do not add generic refactoring suggestion or candidate-discovery machinery.

A new surface should pay for itself by reducing source reads, token use, ambiguity, or unsafe text replacement compared with the existing exact surfaces. Prefer consumption changes and reuse over parallel APIs.

When a feature starts requiring writes, lifecycle transitions, approvals, or execution, stop: that responsibility belongs to a higher owner such as `agent-loop`.

## Validation

Run:

```bash
composer ci
```

For changes touching rename/removal planning or optional semantic enrichment, also preserve the relevant repository dogfood workflows and structural-without-PHPStan behavior.

## Releases

Releases are marker-driven. `.release/<version>.json` must point to a release-ready ancestor commit whose own `CHANGELOG.md` contains the version. Existing tags are immutable.