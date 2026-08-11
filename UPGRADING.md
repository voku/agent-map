# Upgrading

## Generated map state moves below `.agent-loop/`

This is a breaking default-path change. The project/source root does not move;
only generated agent-map state gets a new canonical location.

New defaults:

```text
.agent-loop/map/php-symbols.json
.agent-loop/map/php-symbols.toon
.agent-loop/map/search.sqlite
```

Historical defaults:

```text
.agent-map/php-symbols.json
.agent-map/map/php-symbols.toon
.agent-map/search.sqlite
```

Migrate existing generated state explicitly:

```text
.agent-map/php-symbols.json      -> .agent-loop/map/php-symbols.json
.agent-map/map/php-symbols.toon -> .agent-loop/map/php-symbols.toon
.agent-map/search.sqlite         -> .agent-loop/map/search.sqlite
```

Because map/search state is derived, rebuilding it is also valid and often
cleaner than moving it. Explicit `--out`, `--index`, and `--database` options
remain authoritative. There is no automatic fallback or dual-write to
`.agent-map/`.
