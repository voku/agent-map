# Polyglot evidence provider experiment

Status: experimental tooling only. This does not change `agent-map`'s PHP map model, public API, dependency set, or evidence guarantees.

## Question

Can `agent-map` gain useful evidence for non-PHP repositories without weakening the proof boundary that its PHP backend currently provides?

The first providers are deliberately different:

- `rg` as the zero-index text-search baseline;
- [SigMap](https://github.com/manojmallick/sigmap) as broad deterministic retrieval evidence;
- [Tree-sitter](https://tree-sitter.github.io/tree-sitter/) as language-aware syntax/declaration evidence;
- [SCIP](https://github.com/scip-code/scip) plus language indexers as semantic definition/reference evidence.

The experiment does **not** assume one provider should win every capability. A cheap discovery provider and a stronger semantic provider may remain separate channels.

## Why this stays outside the production model

`agent-map`'s current index is intentionally PHP-shaped and its stronger contracts depend on PHPStan-backed semantics. Generalizing that model before measuring foreign-language evidence would turn an experiment into architecture by enthusiasm.

This spike therefore defines only normalized **measurement results**. It does not add a new `AgentMapIndex` schema, a production backend interface, or runtime dependencies.

A provider task may be `answered`, `not_found`, `unavailable`, or `error`. An unavailable semantic channel must never masquerade as proof of absence.

## One-click GitHub Actions run

The workflow is `.github/workflows/polyglot-evidence-experiment.yml`.

While this experiment is in a pull request, changes to the workflow, corpus, adapters, scorer, tests, or this document trigger it automatically through `pull_request`.

After the workflow exists on the default branch it can also be started manually with **Actions → Polyglot evidence experiment → Run workflow** through `workflow_dispatch`.

The workflow uses one isolated matrix job per provider and a separate score job:

```text
pinned corpus
    |
    +-- materialize exact upstream commits
    |
    +-- rg ----------> rg.json -----------+
    +-- SigMap ------> sigmap.json -------+
    +-- Tree-sitter -> tree-sitter.json --+--> scorer --> scoreboard.tsv
    +-- SCIP --------> scip.json ---------+
```

Provider jobs upload raw normalized JSON plus their command logs. The score job downloads all four provider artifacts, refuses to continue if any result is missing, writes the scoreboard to `GITHUB_STEP_SUMMARY`, and uploads the complete collected evidence as `polyglot-evidence-scoreboard`.

A provider that executes successfully but cannot answer a capability should emit `unavailable`; that remains valid benchmark data. Infrastructure failure that prevents a provider result from being produced fails the score job instead of quietly shrinking the comparison set.

## Pinned toolchain

The workflow pins candidate versions rather than inheriting whatever happens to be newest on the runner:

| Provider | Experimental version |
| --- | --- |
| ripgrep | `15.2.0` Linux musl release, SHA-256 verified before use |
| SigMap | `8.31.0` |
| Tree-sitter Node binding | `0.25.1` |
| Tree-sitter JavaScript grammar | `0.25.0` |
| Tree-sitter Python grammar | `0.25.0` |
| SCIP CLI | `0.9.0` |
| scip-typescript | `0.4.0` |
| scip-python | `0.6.6` |

The result JSON also records the versions observed at runtime. Node is held at 20 because `scip-typescript` explicitly supports Node 18/20. The SCIP CLI build uses Go 1.25.x because the pinned SCIP release declares Go 1.25.

These tools exist only in experiment jobs. None is added to Composer or the `agent-map` runtime dependency graph.

## Corpus

`tools/polyglot-evidence/corpus.json` is the initial pinned corpus. It contains real JavaScript and Python tasks against exact upstream commits:

- `manojmallick/sigmap@9297a43660fe68230992cc20144adeb3b8a1e5e8`;
- `pallets/flask@d73fa1cdcbd8b1465c151db8924ba58b1dd14e35`.

`tools/polyglot-evidence/prepare-repositories.php` materializes those revisions into an isolated work directory and verifies that each checkout resolves to the exact requested commit before a provider is allowed to run.

Each corpus task has two different kinds of data:

- the **probe**, which is provider input and may contain a natural-language question, symbol identity, or text terms;
- the **expectation**, which is scorer-only ground truth (`expected_paths` / `expected_relations`).

Adapters do not receive expected paths or relations as query input. Otherwise the benchmark would achieve spectacular accuracy by being told the answer, an approach sadly not limited to software evaluation.

The initial corpus is intentionally small enough to audit by hand. Grow it only with pinned real repositories and reviewed expectations. Synthetic fixtures are useful for adapter contract tests, but they are not evidence that a provider should enter production.

## Capability-shaped probes

Providers are compared only where they expose a defensible operation.

### Locate tasks

- `rg` searches the task's fixed text terms and ranks paths by the number of terms matched.
- SigMap runs `sigmap ask <question> --json --no-squeeze` and preserves its ranked files.
- Tree-sitter parses the repository and locates exact declaration names from the syntax tree.
- SCIP locates documents that define the requested symbol.

### Relation tasks

The first experiment does **not** invent a generic relation translator for tools that do not expose comparable semantic evidence:

- `rg`: `unavailable`;
- SigMap: `unavailable` for this benchmark shape;
- Tree-sitter: `unavailable`;
- SCIP: resolves source and target definitions and accepts either a source occurrence referencing the target symbol or an explicit SCIP symbol relationship.

This is deliberate. A heuristic adapter that manufactures a relation would benchmark our adapter as much as the provider and then assign the provider confidence it never claimed.

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

Allowed task statuses:

- `answered`: the provider returned evidence/candidates;
- `not_found`: the provider supports the capability but claims no matching evidence exists;
- `unavailable`: this provider does not expose this capability in the experiment;
- `error`: execution or indexing failed.

`not_found` is intentionally different from `unavailable`. A false `not_found` is counted as a false absence; `unavailable` is a coverage limitation, not an absence claim.

Paths are repository-relative and use `/`. Relations currently use the benchmark-only shape:

```text
source/path->target/path
```

That representation is intentionally crude. It is an interchange shape for measurement, not a proposed public graph contract.

## Run locally

Materialize the corpus repositories:

```bash
php tools/polyglot-evidence/prepare-repositories.php \
  --corpus=tools/polyglot-evidence/corpus.json \
  --workdir=/tmp/agent-map-polyglot/repos \
  --manifest=/tmp/agent-map-polyglot/repositories.json
```

After installing one provider toolchain, collect one result, for example:

```bash
node tools/polyglot-evidence/run-provider.cjs \
  --provider=rg \
  --corpus=tools/polyglot-evidence/corpus.json \
  --repositories=/tmp/agent-map-polyglot/repositories.json \
  --out=/tmp/agent-map-polyglot/rg.json \
  --log=/tmp/agent-map-polyglot/rg.log
```

After all four results exist, score them:

```bash
php tools/polyglot-evidence-experiment.php \
  --corpus=tools/polyglot-evidence/corpus.json \
  --results=/tmp/agent-map-polyglot/rg.json,/tmp/agent-map-polyglot/sigmap.json,/tmp/agent-map-polyglot/tree-sitter.json,/tmp/agent-map-polyglot/scip.json
```

GitHub Actions is the canonical experiment environment because it installs the exact candidate versions and retains raw evidence.

## Score

The scorer reports:

- **coverage**: tasks for which the provider supports the capability (`answered` + `not_found`);
- `unavailable` and `error` task counts;
- `hit@1` and `hit@5` across the corpus;
- **false-absence rate**: a supported provider returned `not_found` although the pinned ground truth contains expected evidence;
- relation precision/recall for tasks with exact expected relations;
- mean query time when supplied;
- cold/warm indexing time and persisted index bytes when supplied.

Rows are ordered by false-absence rate first, then hit@5, coverage, and hit@1. For an evidence system, confidently saying that something does not exist is more dangerous than returning the right file one rank later.

Zero-index providers report zero index time/bytes. Indexed providers measure cold and warm passes independently. SCIP additionally records the cost of materializing the binary index through `scip print --json`; that extra field is retained in raw evidence even though it is not currently a ranking metric.

## Provider adapter rules

An experiment adapter must:

1. use exactly the repository commit named by the corpus;
2. record the observed provider/tool version;
3. build any required index without changing the pinned source revision;
4. answer the probe without receiving `expected_paths` or `expected_relations` as query input;
5. normalize only observable provider output into the result schema;
6. return `unavailable` when the capability does not exist instead of synthesizing an empty answer;
7. preserve execution/index failures as `error` and retain the raw command log;
8. measure cold and warm indexing separately when the provider persists derived state.

Do not use another LLM to translate a corpus question into provider-specific symbols. That would benchmark the translator as much as the provider.

## Decision gate

No polyglot production architecture follows from one attractive run.

A provider becomes an integration candidate only if an expanded real-repository corpus demonstrates all of the following for at least one concrete consumer question:

1. materially fewer source reads or materially better retrieval than the text-search baseline;
2. no silent capability failure and a bounded false-absence rate appropriate to the advertised confidence;
3. provenance/freshness information sufficient for `agent-map` to avoid presenting stale evidence as current;
4. setup, index size, cold cost, and warm cost acceptable for the intended workflow;
5. evidence representable without weakening the existing PHP contracts.

Expected architectural hypotheses to test, not conclusions:

- SigMap may be useful as a low-cost discovery channel or benchmark competitor, but heuristic relations must not be promoted to semantic certainty.
- Tree-sitter may be useful for exact syntax/ranges where no semantic indexer exists.
- SCIP may be useful as a language-neutral semantic interchange format where a trustworthy language indexer exists.
- PHP remains on the current parser + PHPStan owner path unless evidence demonstrates a concrete regression-free reason to change it.

## Next evidence slice

Once the Actions run is stable, expand the corpus before generalizing any production model:

1. add at least one additional language and repository;
2. add consumer-shaped questions from real coding-agent work rather than library-demo questions alone;
3. record false positives as well as misses for relation evidence;
4. compare source reads / bytes consumed by a coding-agent workflow, not only retrieval rank;
5. repeat runs to identify variance before treating timing differences as meaningful.

Only then decide whether a language-neutral evidence owner contract is justified.
