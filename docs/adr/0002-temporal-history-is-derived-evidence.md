# ADR 0002: Temporal history is derived evidence

- **Status:** Accepted
- **Date:** 2026-08-11
- **Scope:** `voku/agent-map`, consumed by `voku/agent-loop`

## Context

The canonical map describes the repository now. Repeated map builds also reveal useful change evidence:
symbols disappear, signatures change, graph centrality moves, and files change together even when no
current semantic edge connects them.

Keeping that evidence is useful only if it does not turn a convenience database into a second source
of truth. The existing search ADR already makes `.agent-map/search.sqlite` disposable. Temporal data
needs the same discipline.

## Decision

The sources remain deliberately separate:

```text
Git revisions                     authoritative project history
      │
      ├── current checkout ──► php-symbols.json   canonical current structure
      │                              │
      │                              ├──► search.sqlite    disposable retrieval projection
      │                              └──► history.sqlite   rebuildable temporal projection
      │
      └── commit changes ─────► temporal co-change evidence
```

### Structural diff

`history diff` compares two canonical maps and emits facts about file, symbol, method, and semantic
relation changes. Relation identity excludes source line numbers. Moving a call because imports were
added above it is not architectural evolution.

### Git co-change

`history coupling` reads a bounded number of non-merge Git commits, filters them to currently indexed
PHP files, and compares repeated co-change with the current semantic/path coupling graph. Very large
commits are skipped by default because repository-wide formatting is poor coupling evidence.

### Temporal snapshots

`history observe` writes `.agent-map/history.sqlite` only when tracked Git files are clean. Each
snapshot records the Git revision, map digest, and compact symbol/method observations:

- declaration signature;
- file and inferred architecture region;
- unique callers and callees;
- unique dependents and dependencies.

It deliberately stores no source text, embeddings, session output, or raw relation-event history.
Deleting the database loses a projection, not project truth. It can be reconstructed by checking out
Git revisions and rebuilding maps.

Snapshot ids define observation order. Git timestamps remain metadata only. Rebase, cherry-pick, and
synthetic merge commits make timestamps unsuitable as an ordering relation; self-dogfood exposed this
before release.

### Claims

Temporal facts and claims are different types of output. A claim must:

1. be explicitly marked heuristic;
2. expose the evidence and thresholds that produced it;
3. never replace current structural facts;
4. remain safe to discard and recompute.

The first supported claim is `hidden_temporal_coupling`: repeated strong co-change between two files
when the current semantic graph has no edge between them. It does **not** claim the architecture is
wrong. It identifies a relationship worth investigating.

## Consequences

The agent gains a time dimension without a graph server, daemon, model call, or persistent external
service. SQLite stays portable and local. Current structural analysis remains deterministic and usable
without SQLite.

The cost is deliberate incompleteness. `signature_instability`, `region_drift`, `growing_centrality`,
rename inference, and broader smell classification are deferred until real accumulated snapshot
history can validate them. Synthetic fixtures are sufficient for mechanics, not for proving a smell
is useful.
