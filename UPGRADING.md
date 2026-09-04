# Upgrading

See [docs/stability.md](docs/stability.md) for the tier of every public surface and for what 1.0
freezes. Anything classified there as *experimental* or *subtraction candidate* may still change in a
0.9.x release.

## Package assets and third-party notices layout

Following the shared repository layout convention:
- The package Make include moved from root `Makefile.agent-map.mk` to `resources/make/agent-map.mk`. Runtime code can locate it with `PackageResources::makeInclude()` or `PackageResources::MAKE_INCLUDE`.
- `THIRD_PARTY_NOTICES.md` moved from root to `docs/reference/third-party-notices.md`.

## 0.8 to 0.9

### Removed pre-0.9 compatibility aliases

`MethodRenamePlan::$backend` and `MethodRenamePlan::$mapDigest`, together with the duplicated
top-level `backend` and `map_digest` keys in `method_rename_plan` JSON/TOON output, were announced in
0.8.4 as pre-0.9 compatibility aliases. They are gone. Read the evidence identity from `provenance`:

```php
$plan->provenance->backend;
$plan->provenance->mapDigest;
$plan->provenance->analysisFingerprint;
```

```jq
.provenance.backend, .provenance.map_digest
```

The contract version is unchanged: `provenance` already carried the same values in 0.8, so a consumer
that already reads it needs no change.

### One provenance type for the whole plan family

`MethodRenameProvenance` was a duplicate of the shared provenance type with an identical shape. It
has been removed; `MethodRenamePlan` and `ParameterRenamePlan` now expose `PlanProvenance` like every
other governed rename, removal and move plan. Machine output is unchanged.

Type hints referring to `voku\AgentMap\Rename\MethodRenameProvenance` must be updated to
`voku\AgentMap\Plan\PlanProvenance`.

### One behaviour contract for the plan family

Every governed rename, removal and move plan now implements
`voku\AgentMap\Plan\GovernedPlan`, which declares `isBlocked(): bool` and `toArray(): array`. This is
additive: the concrete plan classes, their constructor signatures and their machine output are
unchanged. Consumers that handle several plan types can now type against the interface instead of a
union.

The concrete plans stay separate types on purpose. A class move and a constant removal carry
genuinely different evidence; only the behaviour a mutation host depends on is shared.

### A map from another schema major is now rejected

`IndexReader` never checked `schema_version`. A map written by a schema-1.x agent-map was read
anyway, reconstructed from whichever fields happened to match, and then answered structural questions
with silence. Reading a map this build does not understand now fails:

```text
Unsupported agent map schema version 1.0; this build reads 2.x. Rebuild the map with agent-map build.
```

`AgentMapIndex::SCHEMA_VERSION` is what this build writes and `AgentMapIndex::SUPPORTED_SCHEMA_MAJOR`
is what it reads. Minor versions inside the supported major stay readable. If a project still has a
pre-2.0 map on disk, run `agent-map build` once; there is no migration path and there should not be
one, because a rebuild is cheap and a half-understood map is not.

The 1.x-only field readers this makes unreachable were removed with it: the `sha1` fallback on
`FileEntry`, the legacy `return_type` on `SymbolEntry` and `MethodEntry`, the `params` alias, and
`ParameterEntry::fromLegacyString()`.

### Shared plan value objects moved to `Plan\`

The value objects the whole plan family uses were named and namespaced as if only renames existed.
They now live where they belong:

| 0.8 | 0.9 |
| --- | --- |
| `voku\AgentMap\Rename\RenameProvenance` | `voku\AgentMap\Plan\PlanProvenance` |
| `voku\AgentMap\Rename\RenameEdit` | `voku\AgentMap\Plan\PlanEdit` |
| `voku\AgentMap\Rename\RenameBlindSpot` | `voku\AgentMap\Plan\PlanBlindSpot` |
| `voku\AgentMap\Rename\RenameStaleEvidence` | `voku\AgentMap\Plan\PlanStaleEvidence` |
| `voku\AgentMap\Rename\RenameMove` | `voku\AgentMap\Plan\PlanMove` |

Property names, constructor signatures and machine output are unchanged; this is a type rename only.

### `rename-capabilities` is now `plan-capabilities`

Capability discovery covers all ten governed contracts instead of the six rename ones. The payload
`type` changed from `rename_capabilities` to `plan_capabilities`, and each capability gained a
`family` field (`rename`, `removal` or `move`):

```bash
vendor/bin/agent-map plan-capabilities --format=json
```

`voku\AgentMap\Cli\RenamePlanCapability` became `voku\AgentMap\Plan\PlanCapability` (with the new
`family` constructor argument first), and `voku\AgentMap\Cli\RenamePlanCliApplication` became
`voku\AgentMap\Cli\PlanCliApplication`, now implemented by the removal and move boundaries too.

### `rank` is removed

`agent-map rank` had no consumer and its one-hop neighbour count is derivable from `callers` and
`callees`. Use those, or `discover`, which still ranks internally. `voku\AgentMap\Discovery\GraphRanker`
remains in the package as an implementation detail of `discover` and is no longer public API.

### New governed contract

`class_move_plan@1.0` is available through `agent-map class-move-plan` and
`voku\AgentMap\Move\ClassMovePlanner`. It is additive; nothing existing changes behaviour. See
[docs/class-move.md](docs/class-move.md).

### CLI changes at a glance

| 0.8 | 0.9 |
| --- | --- |
| `rename-capabilities` | `plan-capabilities` (all ten contracts, `family` field, payload `type` renamed) |
| `rank` | removed; use `callers`, `callees` or `discover` |
| - | `class-move-plan` (new) |
| `search`, `search-index` (undocumented) | documented in `agent-map help` and the README |

Exit codes are unchanged and uniform: a governed plan command exits `1` when the plan is `blocked`
and `0` otherwise.

## Optional semantic backends

`SemanticAnalyzer` now exposes `backend(): string`. Custom analyzer implementations must return a stable backend identity so generated maps can record which semantic capability produced their relations and incremental builds can reject cross-backend merges.

PHPStan is no longer a runtime dependency of `agent-map`. Projects that require PHPStan-backed semantic enrichment must install `phpstan/phpstan` themselves; otherwise builds use the structural-only backend. PHPStan-specific build options fail explicitly when that capability is unavailable rather than being ignored.

## Standalone state remains below `.agent-map/`

`agent-map` keeps its standalone generated state below the package-owned
`.agent-map/` directory:

```text
.agent-map/php-symbols.json
.agent-map/php-symbols.toon
.agent-map/search.sqlite
.agent-map/history.sqlite
.agent-map/structural-cache.json
.agent-map/phpstan-cache/
```

The unreleased experiment that moved some defaults to `.agent-loop/map/` is not
a public migration contract and has been removed. `agent-map` does not need to
know the layout policy of an embedding package.

Embedding applications may instead construct the public CLI application with a
map artifact root. That mount point changes the defaults for all generated map
state while explicit `--out`, `--index`, and `--database` options remain
authoritative.
