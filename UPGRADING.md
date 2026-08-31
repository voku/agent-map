# Upgrading

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

`MethodRenameProvenance` was a duplicate of `RenameProvenance` with an identical shape. It has been
removed; `MethodRenamePlan` and `ParameterRenamePlan` now expose `RenameProvenance` like every other
governed rename, removal and move plan. Machine output is unchanged.

Type hints referring to `voku\AgentMap\Rename\MethodRenameProvenance` must be updated to
`voku\AgentMap\Rename\RenameProvenance`.

### One behaviour contract for the plan family

Every governed rename, removal and move plan now implements
`voku\AgentMap\Plan\GovernedPlan`, which declares `isBlocked(): bool` and `toArray(): array`. This is
additive: the concrete plan classes, their constructor signatures and their machine output are
unchanged. Consumers that handle several plan types can now type against the interface instead of a
union.

The concrete plans stay separate types on purpose. A class move and a constant removal carry
genuinely different evidence; only the behaviour a mutation host depends on is shared.

### New governed contract

`class_move_plan@1.0` is available through `agent-map class-move-plan` and
`voku\AgentMap\Move\ClassMovePlanner`. It is additive; nothing existing changes behaviour. See
[docs/class-move.md](docs/class-move.md).

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
