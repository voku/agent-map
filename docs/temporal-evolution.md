# Temporal evolution

`agent-map` can now answer not only "what is this PHP repository?" but also "how is it changing?".
The temporal commands keep facts, historical observations, and heuristic claims separate.

## Compare two maps

```bash
agent-map history diff \
  --before=.agent-map/before.json \
  --after=.agent-map/php-symbols.json
```

This reports structural lifecycle facts such as added/removed methods, declaration changes, symbol
movement, and semantic relation changes. Line-number-only movement is ignored.

## Find temporal coupling

```bash
agent-map history coupling --commits=100 --top=20
```

The report shows repeated Git co-change beside current static coupling. A pair that changes together
without a semantic graph edge can reveal a relationship the static snapshot cannot see.

Large commits are skipped by default. Tune the evidence window explicitly when necessary:

```bash
agent-map history coupling \
  --commits=250 \
  --min-cochanges=3 \
  --max-files-per-commit=50
```

## Inspect claims

```bash
agent-map history claims --commits=100 --min-ratio=0.6
```

Claims are always marked heuristic and include their evidence. The first claim type,
`hidden_temporal_coupling`, means only this:

- the files repeatedly changed together;
- the smaller side participated in at least the configured fraction of those changes;
- the current semantic graph has no direct edge between them.

It is an investigation lead, not a refactoring order.

## Record compact history

Build or refresh a current map, then record a clean Git revision:

```bash
agent-map history observe
```

The default `.agent-map/history.sqlite` stores compact symbol/method observations: declaration,
location, architecture region, callers/callees, and dependents/dependencies. It stores no source code
or embeddings.

The tracked working tree must be clean so the observation remains reproducible from Git.

Inspect an entity later:

```bash
agent-map history show 'method:App\PaymentService::process'
```

The result exposes lifecycle status, signature/path/region variants, and raw graph metric deltas.
A method observed previously but missing from the latest recorded snapshot is reported as
`absent_from_latest`.

## Storage model

```text
php-symbols.json   canonical current structure
search.sqlite      disposable search projection
history.sqlite     rebuildable temporal projection
Git                authoritative historical source
```

Deleting either SQLite database must not destroy project truth. See ADR 0002 for the evidence and
source-of-truth boundary.
