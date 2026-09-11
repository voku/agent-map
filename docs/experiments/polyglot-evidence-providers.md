# Polyglot evidence provider experiment

Status: experimental tooling only. This does not change `agent-map`'s PHP map model, public API, dependency set, or evidence guarantees.

## Question

Can `agent-map` gain useful evidence for non-PHP repositories without weakening the proof boundary that its PHP backend currently provides?

The first providers to compare are deliberately different:

- `rg` / equivalent text search as the zero-index baseline;
- [SigMap](https://github.com/manojmallick/sigmap) as a broad deterministic signature/retrieval implementation;
- [Tree-sitter](https://tree-sitter.github.io/tree-sitter/) as a language-aware syntax/range provider;
- [SCIP](https://github.com/scip-code/scip) plus an appropriate language indexer as a semantic reference/definition provider.

The experiment does **not** assume that one provider must win every row. A cheap discovery provider and a stronger semantic provider may remain separate channels.

## Why this is outside the production model

`agent-map`'s current index is intentionally PHP-shaped and its stronger contracts depend on PHPStan-backed semantics. Generalizing that model before we have measured foreign-language evidence would turn an experiment into architecture by enthusiasm.

This spike therefore defines only a normalized **measurement result**, not a new `AgentMapIndex` schema or backend interface.

A provider result may say that a task was answered, not found, unavailable, or errored. An unavailable semantic channel must never be scored as proof of absence.

## Corpus

`tools/polyglot-evidence/corpus.json` is the initial pinned corpus. It currently contains real JavaScript and Python tasks against exact upstream commits:

- `manojmallick/sigmap@9297a43660fe68230992cc20144adeb3b8a1e5e8`;
- `pallets/flask@d73fa1cdcbd8b1465c151db8924ba58b1dd14e35`.

The initial tasks exercise declaration/file discovery and a small number of mechanically checkable file relations. They are intentionally small enough to inspect by hand. Grow the corpus only with pinned, reviewed expectations.

Synthetic fixtures are suitable for adapter contract tests, but they do not count as evidence that a provider should become part of `agent-map`.

## Normalized provider result

Each provider writes one JSON document:

```json
{
  "schema": "agent-map-polyglot-provider-result@1",
  "provider": "sigmap",
  "version": "8.31.0",
  "metadata": {
    "cold_index_ms": 1234,
    "warm_index_ms": 45,
    "index_bytes": 987654
  },
  "tasks": [
    {
      "id": "sigmap.file-dependency-graph",
      "status": "answered",
      "paths": ["src/graph/builder.js"],
      "relations": [],
      "elapsed_ms": 8.4
    }
  ]
}
```

Allowed status vocabulary for the experiment:

- `answered`: the provider returned evidence/candidates;
- `not_found`: the provider claims the requested fact is absent;
- `unavailable`: this provider cannot answer this task/capability;
- `error`: execution/indexing failed.

`not_found` is intentionally different from `unavailable`. The whole point is to stop a missing capability from masquerading as absence.

Paths are repository-relative and use `/`. Relations use the first experiment representation:

```text
source/path->target/path
```

That representation is intentionally crude. It is a benchmark interchange shape, not a proposed public graph contract.

## Score

Run:

```bash
php tools/polyglot-evidence-experiment.php \
  --corpus=tools/polyglot-evidence/corpus.json \
  --results=/tmp/rg.json,/tmp/sigmap.json,/tmp/tree-sitter.json,/tmp/scip.json
```

The scorer reports:

- `hit@1` and `hit@5` across all corpus tasks;
- **false-absence rate**: a provider returned `not_found` although the pinned corpus contains expected evidence;
- relation precision/recall for tasks with exact expected relations;
- mean query time when supplied;
- cold/warm indexing time and persisted index bytes when supplied.

Rows are ordered by false-absence rate first, then hit@5 and hit@1. For an evidence system, a confidently wrong absence is more dangerous than returning the right file one rank later.

## Provider adapter rules

Adapters are intentionally kept out of the first commit. Each candidate tool has a different stable interface and output shape, and embedding those assumptions in `agent-map` before the benchmark would already create the dependency we are trying to evaluate.

An adapter should:

1. checkout exactly the repository commit named by the corpus;
2. record the exact provider/tool version;
3. build any required index without changing the repository source;
4. answer the corpus question without being given `expected_paths` or `expected_relations`;
5. normalize only observable provider output into the result schema;
6. return `unavailable` when a capability does not exist instead of synthesizing an empty answer;
7. preserve errors as `error`, with the raw log stored separately from the scored JSON;
8. measure cold and warm indexing separately when the provider persists derived state.

Do not use another LLM to translate the question into provider-specific symbols during this experiment. That would benchmark the translator as much as the provider.

## Decision gate

No polyglot production architecture follows from one attractive result.

A provider becomes an integration candidate only if the expanded real-repository corpus demonstrates all of the following for at least one concrete consumer question:

1. materially fewer source reads or materially better retrieval than the text-search baseline;
2. no silent capability failure and a bounded false-absence rate appropriate to the advertised confidence;
3. provenance/freshness information sufficient for `agent-map` to avoid presenting stale evidence as current;
4. setup, index size, cold cost, and warm cost that are acceptable for the intended workflow;
5. evidence that can be represented without weakening the existing PHP contracts.

Expected architectural hypotheses to test, not conclusions:

- SigMap may be useful as a low-cost discovery channel or benchmark competitor, but heuristic relations must not be promoted to semantic certainty.
- Tree-sitter may be useful for exact syntax/ranges where no semantic indexer exists.
- SCIP may be useful as a language-neutral semantic interchange format where a trustworthy language indexer exists.
- PHP remains on the current parser + PHPStan owner path unless evidence shows a concrete regression-free reason to change it.

## Next slice after the harness lands

Implement adapters outside production code, collect results for all four providers, expand the corpus to at least one additional language/repository, and publish the raw result files plus the exact tool versions. Only then decide whether a language-neutral evidence contract is justified.
