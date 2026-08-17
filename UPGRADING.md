# Upgrading

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
