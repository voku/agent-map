# Method rename planning

`agent-map rename-plan` turns a current PHPStan-backed map into a deterministic, read-only plan for renaming one PHP method family.

It does **not** modify source code. The map owns semantic identity and evidence; a surrounding coding agent or host owns source mutation and validation.

```bash
vendor/bin/agent-map rename-plan \
  'App\Service\UserService::save' \
  persist \
  --format=json
```

## Evidence model

The planner combines two existing authorities:

1. PHPStan-backed `agent-map` relations decide which declaration or call target a source expression refers to.
2. `voku/simple-php-code-parser` supplies the names-resolved php-parser AST and exact identifier byte positions.

Each planned edit records:

- repository-relative file;
- indexed source SHA-256;
- exact inclusive byte range;
- source line range;
- expected current identifier;
- replacement identifier;
- declaration/call role;
- semantic target and resolution.

A mutation host must re-check the file hash and expected token before applying any edit. Edits should be applied from the highest byte offset to the lowest within each file, then the map, PHPStan and project tests should be rebuilt/run.

## Public plan contract

`MethodRenamePlan` and its JSON/TOON representation are the public, typed consumption boundary. Machine output contains `type: method_rename_plan` and `contract_version: 1.0`. Consumers should reject a contract version they do not support rather than infer compatibility.

The `provenance` object identifies all evidence used by the plan:

- `map_digest`: deterministic digest of the complete indexed map;
- `backend`: the effective structural or PHPStan backend recorded by that map;
- `source_hashes`: repository-relative path to indexed SHA-256 for every mapped PHP source file;
- `analysis_fingerprint`: PHPStan version/reference, configuration and lock hashes, and source digest when the map carries semantic analysis provenance (otherwise `null`).

The pre-0.9 top-level `backend` and `map_digest` fields remain compatibility aliases for the corresponding provenance values. New consumers should use `provenance`; the aliases may be removed in a future major contract version.

Each edit repeats its own source hash so an applying host can validate an edit without reconstructing plan-wide provenance. `family`, `edits`, `blind_spots`, `stale_evidence`, `blockers`, and `not_observable` are always present, including when empty. Blocked plans never publish edits.

Staleness is not a semantic blocker. Source snapshot mismatches are emitted as typed `stale_evidence` entries with a path and reason (`missing` or `hash`); semantic safety failures remain in `blockers`. Either makes the plan `blocked`, but consumers can respond to stale evidence by rebuilding instead of presenting it as a refactoring ambiguity.

## Status

### `safe`

Every observed declaration in the override/interface family and every PHPStan-resolved call into that family mapped to exactly one source identifier. No observable dynamic dispatch remains.

### `review_required`

All deterministic edits are available, but the map contains a concrete dynamic-dispatch blind spot on a receiver type belonging to the rename family. A human or coding agent must decide whether the dynamic use also carries the old method name.

### `blocked`

No edits are published. Blocking conditions include:

- stale source/map evidence (reported separately in `stale_evidence`);
- structural-only map without PHPStan semantic call identity;
- an override/prototype outside the indexed map;
- trait methods, until trait aliases and `insteadof` adaptations are explicit rename evidence;
- a replacement name that already collides in a related type;
- a call that may target both a rename-family method and a method outside the family;
- semantic evidence that cannot be mapped to exactly one parser identifier token;
- overlapping edit ranges.

Failing closed is deliberate. A refactoring command that guesses which `save()` you meant is just search-and-replace wearing a tie.

## Observation boundary

The plan does not claim to rewrite what the map cannot prove. In particular, the following remain outside automatic mutation:

- string callbacks;
- reflection-driven method names;
- framework/container configuration that names methods outside PHPStan call relations;
- dynamically constructed names whose receiver cannot be tied to the rename family;
- PHP source outside the indexed map scope.

These are either surfaced as concrete blind spots when observable or retained as explicit `not_observable` limitations in the plan.

## Why method rename comes first

Method calls already have strong PHPStan target identity and bounded source evidence. Class rename has a broader surface: imports and aliases, PHPDoc names, `::class`, attributes, serialized names, configuration, reflection and framework metadata. Keeping class rename as a later slice avoids pretending those extra surfaces are solved merely because method calls are.
