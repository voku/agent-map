# ADR 0001: Hybrid search is a derived index, not a new source of truth

- **Status:** Accepted
- **Date:** 2026-08-05
- **Scope:** `voku/agent-map`, consumed by `voku/agent-recall-compiler` and `voku/agent-loop`

## Context

`agent-map` answers questions by identifier: give it a symbol, a file or a relation and it returns
canonical facts with provenance. It cannot answer "how are retry timeouts handled" unless the code
happens to contain that vocabulary. A hybrid index - lexical plus semantic - would close that gap.

The risk is not the retrieval quality. It is that a semantic store looks like a better map: it has
tables, it ranks, it feels authoritative. If it becomes the source of truth, every property this
package currently guarantees is spent at once: deterministic artifacts, canonical ids, source
hashes, a map that can be rebuilt byte-identically and diffed by a human.

## Decision

The canonical map stays canonical. Hybrid search is a **derived, disposable cache**:

```text
.agent-map/php-symbols.json     canonical structural map (deterministic, portable, committed decision)
            │
            ▼
.agent-map/search.sqlite        derived hybrid-search index (disposable, rebuildable)
```

Binding rules:

1. Every chunk in the search index is derived from a canonical symbol id and its recorded line
   range. Chunking never introduces a second parser pipeline.
2. Every search result carries the map snapshot it was derived from. A result whose snapshot does
   not match the consumer's map is unusable, not merely stale.
3. Deleting `search.sqlite` loses no information. It is rebuilt from the map plus the working tree.
4. Structural results stay authoritative; semantic results are additive context. Semantic similarity
   never becomes an edit obligation, a verification answer, or a map fact.
5. No existing command (`build`, `refresh`, `query`, `file`, `stale`, `summary`, `changed`,
   `related`, `stats`, `scope`, `callers`, `callees`, `context`) gains a SQLite dependency.

## Measured capability reality

Taken on 2026-08-05 rather than assumed, because the plan this ADR closes rests on them:

| environment | PHP | sqlite3 | FTS5 | extension loading |
| --- | --- | --- | --- | --- |
| IT-Portal php container | 8.4.22, SQLite 3.40.1 | yes | **yes** | **disabled** (`sqlite3.extension_dir=''`) |
| development host | 8.4.23 | **no** | n/a | n/a |

Three consequences follow directly:

- **FTS5 is the safe floor.** It is compiled into the SQLite the container already ships, needs no
  loadable extension, and therefore carries none of the distribution problem. The lexical channel
  can be built and benchmarked before any native-extension work exists.
- **`sqlite-vec` cannot be assumed anywhere.** One of the two environments checked has no `sqlite3`
  extension at all, and the other refuses to load extensions. A vector channel must be optional at
  runtime, per environment, not merely optional at install time.
- **`SQLite3::loadExtension()` warns instead of throwing.** With extensions disabled it emitted
  `SQLite Extensions are disabled` and execution continued past the call. A capability probe that
  trusts its return value or the absence of an exception will report a working vector channel that
  does not exist. Capability must be proven by *using* the extension - `SELECT vec_version()` - and
  a failed probe must produce an explicit `degraded` result, never a silent fallback to lexical.

## Version boundary

`agent-map` requires PHP `>=8.2`. `Pdo\Sqlite::loadExtension()` exists only from 8.4, so the
adapter targets `SQLite3::loadExtension()` across all supported versions and may use the PDO variant
only as an 8.4+ detail. `PDO::loadExtension()` does not exist and must not appear in the code.

## Out of scope

Deliberately excluded, each its own project rather than a checkbox here: signed `sqlite-vec` binary
distribution, static PHP builds, in-process ONNX/TransformersPHP execution, ANN indexes, filesystem
watchers, hand-written FFI vector math, and any ingestion of raw session output.

The last one is a boundary, not a backlog item. `agent-map` indexes repository code.
`agent-session` owns working state, `agent-learning` owns reviewed findings. A semantic corpus of
*approved* learning may come later; a terminal failure does not become durable knowledge because it
was captured.

## Consequences

Accepted costs: two representations to keep coherent, an index that can drift and must therefore be
snapshot-checked, and a benchmark obligation - the exit gate for the lexical and vector channels is
measured retrieval quality, not the existence of the tables.

Rejected alternative: migrating the canonical map into SQLite. It would discard incremental refresh,
byte-comparable artifacts and portability, in exchange for a storage engine the current queries do
not need.
