# Upgrading

## Artifact paths are relative to `--root`

`--out`, `--index`, and `--database` now use one path contract: relative values
are resolved against `--root`, while absolute values remain authoritative.

For example:

```text
agent-map build \
  --root=target \
  --out=.agent-loop/map/php-symbols.json

agent-map search-index build \
  --root=target \
  --index=.agent-loop/map/php-symbols.json \
  --database=.agent-loop/map/search.sqlite
```

writes/reads:

```text
target/.agent-loop/map/php-symbols.json
target/.agent-loop/map/search.sqlite
```

Do not repeat the root directory inside a relative artifact option. In
particular, this old ambiguous shape:

```text
--root=target --database=target/.agent-loop/map/search.sqlite
```

now means `target/target/.agent-loop/map/search.sqlite` by definition. Use an
absolute path when an artifact intentionally lives outside `--root`.

This contract was pinned after real consumer dogfood found that `--out` and
`--database` previously looked symmetric while using different path bases.

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
