# Upgrading

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
