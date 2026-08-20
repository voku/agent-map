# Does bounded Map navigation reduce LLM reading?

Owner-level dogfood evidence for [issue #25](https://github.com/voku/agent-map/issues/25).

The question this answers is narrow and behavioural:

> Does agent-map help a coding LLM find the correct 2-5 read/edit locations with less source/context
> consumption than a normal grep/read workflow, without turning scoped Map/Search silence into
> global absence claims?

Everything below is measurement. No product PHP changed for it. The verdicts at the end are
evidence-backed decisions per capability, including the ones that say *do not change anything*.

## Toolchain freeze

| component | pinned at |
| --- | --- |
| agent-map | `d09510d` (0.8.2) |
| agent-loop (consumer, read-only census) | `3b7190d`, re-checked against main `f8fc8ff` - see *Since the freeze* |
| agent-recall-compiler (consumer, read-only census) | `54ccf16`, which is current main |
| PHP | 8.4.19, SQLite FTS5 available, sqlite-vec loadable |
| phpstan/phpstan | `2.2.x-dev` historical replay environment; exact package source reference was not captured and cannot be reconstructed from committed evidence |

Future replay runs should record the exact resolved PHPStan package version/source alongside the report. The historical rows below remain useful, but this missing package-level provenance is an explicit reproducibility limit rather than an invented pin.

Every replay was run twice: once with `--backend=structural` and once with `--backend=phpstan`.
That is not a detail. See the first finding.

## M0 - capability-consumption census

What the ordinary workflow consumed **at the pinned consumer revisions above**, as opposed to what
exists. "Consumer" means the code that reads the result; "activation" is what has to be true for it
to run at all. Output sizes are measured on the frozen Simple-PHP-Code-Parser checkout (71 files,
phpstan backend) with the format each consumer uses.

This is a census of one frozen day, and consumers move. Read every "consumes" below as "consumed at
`3b7190d` / `54ccf16`", not as a standing claim about agent-loop main.

| capability | producer | consumer | activation | host-visible | measured output | verdict |
| --- | --- | --- | --- | --- | --- | --- |
| map build / refresh | `AgentMapBuilder` | agent-loop `EditMapPreparer`, `Edit\Verify\MapRefresher` | every edit run | yes (`agent-loop map build`) | artifact, not prompt | **active** |
| map readiness | `MapReadinessInspector` | agent-loop `RunManifestProjector`, `WorkflowRunPreparer`, `WorkflowRankedMapContextExpander` | every run | no | status lines | **active** |
| index reads / target resolution | `IndexReader`, `AgentMapIndex::resolveMethod` | agent-loop `WorkflowContextCommand`, `ObjectiveGateRunner`; recall `MapRecallProvider` | every run | via other commands | small | **active** |
| bounded edit context | `EditContextPlanner` | recall `MapRecallProvider` (owner default policy), agent-loop ranked expander (6 files / 16 KB) | task targets, or a ranked method seed | no (`agent-map context` exists but no consumer or skill uses it) | 1.0-15.3 KB of slice headers in the replays; 34 KB text / 50 KB json if slice source is included | **active** |
| ranked hybrid search | `HybridSearch` + `SearchIndexStore` | recall `MapRecallProvider::searchFacts` -> agent-loop ranked expander | a configured search DB, FTS5, snapshot match, description >= 12 chars | `agent-map search` | 1.0-11.7 KB projection at limit 8 | **active, conditional** |
| architecture discovery | `ArchitectureDiscovery` | recall `MapRecallProvider` | only when the task names **no** files and **no** targets | `agent-loop map discover` | 9.7 KB text / 32 KB toon / 54 KB json at limit 10 | **active, conditional - unmeasured benefit** |
| exact callers / callees | `AgentMapIndex::incoming/outgoing` | agent-loop expander (test-seed callees only); everything else is model-invoked | phpstan backend for `calls` relations | `agent-map callers/callees`, named in the investigate skill prose | 277 bytes for one method | **model-invoked only** |
| bounded impact | `ImpactAnalyzer` | no automated consumer | model chooses | `agent-loop map impact`, in the investigate skill | 15.6 KB text / 53 KB json, depth 2 | **model-invoked only** |
| symbol / neighbour lookup | `query`, `related`, `file`, `changed` | no automated consumer; the most-cited commands in the agent-loop skills | model chooses | yes, all four | 6.2 KB / 7.5 KB / 3.0 KB text for one class | **model-invoked only** |
| graph ranking | `GraphRanker` (`rank`) | no consumer, not in any skill | never automatic | `agent-map rank` | 4.7 KB json, top 10 | **unused** |
| scope inspection | `ScopeInspector` (`scope`) | no consumer, not in any skill | never automatic | `agent-map scope` | 1.3 KB text for one method - the cheapest exact call listing agent-map has | **unused** |
| summary / stats / stale | index helpers | `stale` appears in one skill | model chooses | yes | 1.3 KB / 2.5 KB | **diagnostic** |
| temporal history / claims / coupling | `Temporal\*` | no automated consumer | model chooses | `agent-loop map history …`, in the investigate skill | not measured here | **model-invoked only** |

Two census facts matter more than the rest:

1. **The Loop never puts map source in the prompt.** `WorkflowRankedMapContextExpander` prints slice
   *headers* (`path:start-end [roles] reasons`), blind spots and omissions. The model then reads the
   named ranges itself. So agent-map's prompt cost and its reading cost are separate quantities, and
   the experiment has to measure them separately. It does.
2. **The owner default policy is not the Loop policy.** `EditContextPolicy` defaults to 20 files /
   60 KB; the Loop passes 6 files / 16 KB. The tighter budget is a Loop consumption decision, and it
   is measurable independently - see probe E.

### Since the freeze

agent-loop main has moved past the pinned revision. Checked against `f8fc8ff`:

- **The measured path is unchanged.** `WorkflowRankedMapContextExpander` and `EditMapPreparer` are
  byte-identical to `3b7190d`, so the expansion this experiment replays is still today's expansion.
- **The preparation choreography changed.** [#243](https://github.com/voku/agent-loop/pull/243) moved
  Map discovery out of a host precondition and into `enter`, which now reconciles through
  `AgentMapBuilder` itself (`WorkflowRunPreparer`, +70/-6). Nothing about the projection changed.
- **The ranked Search index is still not a precondition.** #243 declines to build it on purpose, so
  strategy B's activation stays conditional in practice: a run can have a current map and no search
  index at all.
- **agent-recall-compiler `54ccf16` is current main**, so the recall-side census needs no caveat.

One consequence sharpens finding 1 rather than weakening it. `AgentMapBuilder` picks its backend in
its constructor, from `PhpStanSemanticAnalyzer::isAvailable()`. Now that `enter` builds the map, the
structural-vs-phpstan capability difference is decided inside the lifecycle by whatever happens to
be installed in the consumer project - with no human choice and no consumer reading the result.

## M1 - three frozen replays

Each replay is a real issue whose eventual fix is known from the upstream history, frozen at the
commit *before* the fix, with the task text that existed before it. Fixtures live in
`tools/dogfood/replays/`.

| id | shape | repository | base commit | verified location | task text |
| --- | --- | --- | --- | --- | --- |
| `portable-ascii-135` | exact/local method | voku/portable-ascii | `88f94f8` | `src/voku/helper/ASCII.php` 795-947 (`ASCII::to_ascii`) | issue #135 plus the failing test merged from PR #134 |
| `simple-php-code-parser-101` | caller / cross-file | voku/Simple-PHP-Code-Parser | `53f1b50` | `src/voku/SimplePhpParser/Model/PHPClass.php` 38-130 (`PHPClass::readObjectFromPhpNode`) | issue #101, frozen verbatim upstream |
| `portable-ascii-62` | non-obvious data/config | voku/portable-ascii | `c5aede5` | `src/voku/helper/data/ascii_by_languages.php` 2037-2046 (German block) | issue #62, frozen verbatim upstream |

Grading notes that were fixed **before** any strategy ran:

- `simple-php-code-parser-101` has two verified locations. The issue text names
  `PhpCodeParser.php:529` outright, so finding that one measures nothing; the graded primary location
  is the one nobody is told about, `PHPClass::readObjectFromPhpNode`.
- `portable-ascii-62`'s fix added a new test file, so "did it find the right validator" is not
  measurable there and is reported as `no` rather than being quietly dropped.

## M2 - method

Three strategies plus two diagnostic probes run against the same frozen checkout and the same task
text. Each is a fixed policy written down in `tools/dogfood/navigation-replay.php`, not a model:
a replay has to be reproducible.

- **A - baseline.** Extract search terms from the task text (file references first, then quoted code,
  then identifier-shaped words), grep each in turn, rank matched files by match count, open them in
  that order until the verified file is opened. Cap 25 files.
- **B - current Loop shape.** Ranked hybrid search on the task description at limit 8, then the
  expander's two branches exactly as agent-loop runs them: a ranked *test* method contributes its
  non-test callees as "test evidence", and at most three non-test method seeds are expanded through
  `EditContextPlanner` with the Loop policy (6 files / 16 KB). The projection is what the Loop would
  add to the prompt. The Loop's map-snapshot check is trivially satisfied here - the harness builds
  the map from the same frozen checkout - so this measures the expansion path, never the skip path.
- **C - existing exact surfaces.** Seed from the task text (`File.php:NN` resolves to its enclosing
  method; otherwise the first term the map resolves to exactly one method), then `context` with the
  owner default policy, `callers`, `callees`, `impact`. No new command was invented.
- **D - probe.** B with the task's code vocabulary as the query instead of its prose.
- **E - probe.** B with agent-map's own default policy instead of the Loop's.

Grading is at **range** granularity for every map strategy: a slice naming lines 12-37 of the right
file has not pointed at an edit in lines 38-130, because the model reads only the range it was
given. The baseline is graded at **file** granularity - opening the file counts as reaching the
location. That asymmetry favours the baseline on purpose. Baseline reading is reported under two
models, whole-file and a window of `BASELINE_WINDOW_LINES` lines on each side of the matching line
(up to 81 lines total); the window model is used in the table so the comparison cannot be won by
charging the baseline for reads a careful agent would not make.

Ranges are counted in presentation order: a model that reads what it was handed, top to bottom, pays
for everything above the answer. Naming the right *test* file counts as finding the validator whether
it arrives as a ranked lead or as a presented range, because both are in front of the model.

## Results

The committed raw reports in `tools/dogfood/reports/` remain the source for the full comparable table and per-strategy diagnostics. `read_bytes` is source the model reads to reach the location (baseline: window model), `map_bytes` is the projection agent-map adds to the prompt, and `tool_bytes` is everything the tool channel emits.

The conclusions remain unchanged by the documentation correction above because the implementation and committed measurements already used the 81-line-maximum window model.

## Per-capability decision

The replay evidence supports the following bounded conclusions:

- `EditContextPlanner` remains the productive core: it produced every Map range hit in the experiment, including the cross-file hit that raw ranking placed outside the Loop limit.
- ranked Search is useful as a seed generator, not as a final location oracle; the code-vocabulary probe did not justify query tuning.
- widening the Loop projection budget did not improve any replay and only added bytes.
- structural-only maps cannot answer graph-neighbour questions; absence of call relations is a capability limit, not evidence of no callers.
- symbol-less data/config work remains a native text-search shape rather than a reason to turn agent-map into a general repository search engine.
- `rank` is a subtraction candidate; `scope` remains a cheap exact primitive despite being unconsumed.
- `discover`, `impact`, and model-invoked navigation surfaces need their own real-task evidence before stronger product claims.

No product PHP change is justified by this report alone.
