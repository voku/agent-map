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
| phpstan/phpstan | 2.2.x-dev, installed for the `phpstan` rows only |

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
models, whole-file and a 40-line window around the match; the window model is used in the table so
the comparison cannot be won by charging the baseline for reads a careful agent would not make.

Ranges are counted in presentation order: a model that reads what it was handed, top to bottom, pays
for everything above the answer. Naming the right *test* file counts as finding the validator whether
it arrives as a ranked lead or as a presented range, because both are in front of the model.

## Results

`read_bytes` is source the model reads to reach the location (baseline: window model). `map_bytes`
is the projection agent-map adds to the prompt. `tool_bytes` is everything the tool channel emits
(for the baseline, grep output). `deep_rank` is where the verified range actually sits in the same
ranking at limit 200 - `n/a` means that strategy has no ranking.

| replay | backend | strategy | file_hit | range_hit | files | ranges | read_bytes | map_bytes | tool_bytes | candidates | presented_ranges | false | test | commands | deep_rank |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| portable-ascii-135 | phpstan | A_baseline_grep_read | yes | yes | 3 | 3 | 43953 | 0 | 65697 | 0 | 0 | 2 | yes | 11 | n/a |
| portable-ascii-135 | phpstan | B_loop_ranked_map_context | yes | yes | 1 | 1 | 4812 | 5387 | 5387 | 8 | 19 | 0 | yes | 3 | 2 |
| portable-ascii-135 | phpstan | C_exact_neighbour_surfaces | yes | yes | 1 | 1 | 6640 | 14515 | 14515 | 0 | 16 | 0 | no | 4 | n/a |
| portable-ascii-135 | phpstan | D_probe_code_vocabulary_query | yes | yes | 1 | 1 | 4812 | 5522 | 5522 | 8 | 21 | 0 | yes | 3 | 4 |
| portable-ascii-135 | phpstan | E_probe_owner_default_policy | yes | yes | 1 | 1 | 4812 | 5387 | 5387 | 8 | 21 | 0 | yes | 3 | 2 |
| portable-ascii-135 | structural | A_baseline_grep_read | yes | yes | 3 | 3 | 43953 | 0 | 65697 | 0 | 0 | 2 | yes | 11 | n/a |
| portable-ascii-135 | structural | B_loop_ranked_map_context | yes | yes | 1 | 1 | 6640 | 1017 | 1017 | 8 | 1 | 0 | yes | 3 | 3 |
| portable-ascii-135 | structural | C_exact_neighbour_surfaces | yes | yes | 1 | 1 | 6640 | 278 | 278 | 0 | 1 | 0 | no | 4 | n/a |
| portable-ascii-135 | structural | D_probe_code_vocabulary_query | yes | yes | 1 | 1 | 6640 | 952 | 952 | 8 | 1 | 0 | yes | 3 | 4 |
| portable-ascii-135 | structural | E_probe_owner_default_policy | yes | yes | 1 | 1 | 6640 | 1017 | 1017 | 8 | 1 | 0 | yes | 3 | 3 |
| portable-ascii-62 | phpstan | A_baseline_grep_read | yes | yes | 5 | 5 | 15054 | 0 | 25802 | 0 | 0 | 4 | no | 17 | n/a |
| portable-ascii-62 | phpstan | B_loop_ranked_map_context | no | no | miss | miss | 60927 | 11700 | 11700 | 8 | 21 | 4 | no | 3 | 51 |
| portable-ascii-62 | phpstan | C_exact_neighbour_surfaces | no | no | miss | miss | 0 | 0 | 0 | 0 | 0 | 0 | no | 0 | n/a |
| portable-ascii-62 | phpstan | D_probe_code_vocabulary_query | no | no | miss | miss | 31964 | 3096 | 3096 | 8 | 16 | 3 | no | 3 | 15 |
| portable-ascii-62 | phpstan | E_probe_owner_default_policy | no | no | miss | miss | 82090 | 11220 | 11220 | 8 | 35 | 4 | no | 3 | 51 |
| portable-ascii-62 | structural | A_baseline_grep_read | yes | yes | 5 | 5 | 15054 | 0 | 25802 | 0 | 0 | 4 | no | 17 | n/a |
| portable-ascii-62 | structural | B_loop_ranked_map_context | no | no | miss | miss | 10763 | 1199 | 1199 | 8 | 3 | 1 | no | 3 | 51 |
| portable-ascii-62 | structural | C_exact_neighbour_surfaces | no | no | miss | miss | 0 | 0 | 0 | 0 | 0 | 0 | no | 0 | n/a |
| portable-ascii-62 | structural | D_probe_code_vocabulary_query | no | no | miss | miss | 5280 | 1166 | 1166 | 8 | 3 | 1 | no | 3 | 16 |
| portable-ascii-62 | structural | E_probe_owner_default_policy | no | no | miss | miss | 10763 | 1199 | 1199 | 8 | 3 | 1 | no | 3 | 51 |
| simple-php-code-parser-101 | phpstan | A_baseline_grep_read | yes | yes | 10 | 10 | 43868 | 0 | 57179 | 0 | 0 | 9 | yes | 17 | n/a |
| simple-php-code-parser-101 | phpstan | B_loop_ranked_map_context | yes | yes | 1 | 15 | 27641 | 4166 | 4166 | 8 | 20 | 0 | yes | 3 | 20 |
| simple-php-code-parser-101 | phpstan | C_exact_neighbour_surfaces | yes | no | 1 | miss | 27189 | 15281 | 15281 | 0 | 20 | 0 | no | 4 | n/a |
| simple-php-code-parser-101 | phpstan | D_probe_code_vocabulary_query | no | no | miss | miss | 14540 | 1988 | 1988 | 8 | 6 | 1 | yes | 3 | 124 |
| simple-php-code-parser-101 | phpstan | E_probe_owner_default_policy | yes | yes | 1 | 15 | 27641 | 4130 | 4130 | 8 | 24 | 0 | yes | 3 | 20 |
| simple-php-code-parser-101 | structural | A_baseline_grep_read | yes | yes | 10 | 10 | 43868 | 0 | 57179 | 0 | 0 | 9 | yes | 17 | n/a |
| simple-php-code-parser-101 | structural | B_loop_ranked_map_context | no | no | miss | miss | 3162 | 1136 | 1136 | 8 | 1 | 1 | yes | 3 | 18 |
| simple-php-code-parser-101 | structural | C_exact_neighbour_surfaces | no | no | miss | miss | 8810 | 759 | 759 | 0 | 4 | 1 | no | 4 | n/a |
| simple-php-code-parser-101 | structural | D_probe_code_vocabulary_query | no | no | miss | miss | 0 | 1023 | 1023 | 8 | 0 | 0 | yes | 3 | 122 |
| simple-php-code-parser-101 | structural | E_probe_owner_default_policy | no | no | miss | miss | 3162 | 1136 | 1136 | 8 | 1 | 1 | yes | 3 | 18 |

Raw reports, including every projection verbatim, are in `tools/dogfood/reports/`.

## What the numbers say

### 1. The backend decides whether agent-map has a graph at all

A structural-only map of these repositories contains **zero `calls` relations**: 0 of 131 and 0 of
165 for the two portable-ascii checkouts, 0 of 1,002 for Simple-PHP-Code-Parser. What remains is
declaration structure only - `declares_method`, `defines`, `extends`, `implements`, `uses_trait`.
With phpstan installed the same three builds carry 763, 1,086 and 2,277 call relations.

Everything that walks the call graph degrades accordingly, silently from the consumer's point of
view: `EditContextPlanner` returns the target slice and nothing else, `callers`/`callees`/`impact`
return empty, and the Loop's test-seed callee expansion never fires. That is exactly what killed
both map strategies on the cross-file replay: with phpstan, B reaches the un-named location; without
it, B and C both miss while the baseline still finds it in 10 files.

The fact is already mechanically exposed twice over: `AgentMapIndex::backend` reads
`simple-php-code-parser+structural-only`, readiness hands the index to its consumers, and recall's
`map.snapshot` fact already carries `backend` into the prompt as `navigation_metadata`. What nobody
draws is the *consequence* - structural means the graph questions are unanswerable, not that the
answer is "none". The gap is in consumption, not in agent-map's data, which is a pattern worth
naming: the owner already knows, the projection already carries it, the consumer ignores the
semantic consequence, and the next instinct is to add another API.

### 2. On a local-method task, Map is a large, cheap win

`portable-ascii-135`: the baseline opens 3 files and reads 43,953 bytes (114,086 whole-file) after
65,697 bytes of grep output across 8 terms.

- structural backend: B presents one range and the model reads 6,640 bytes for a 1,017-byte
  projection - **6.6x less source, 65x less tool output**;
- phpstan backend: the rank-1 lead is a *test* method, and its non-test callee is
  `ASCII::to_ascii` itself, so the very first presented range is the fix location: 4,812 bytes read
  for a 5,387-byte projection - **9.1x less source**.

Both backends also put the reproduction test, `tests/AsciiTest.php`, in the ranked leads. C reaches
the same method from the term `to_ascii` for 278 bytes of projection, and never names the test.

### 3. On a cross-file task, exact expansion - not ranking - finds the location

`simple-php-code-parser-101`, phpstan: ranked search puts the verified range at position **20**, far
below the Loop's limit of 8. B still reaches it. The rank-8 seed
`PHPProperty::readObjectFromPhpNode` expands to `PHPClass.php:31-139`, labelled *direct caller that
may need adaptation*, and that slice is the answer. The ranking supplied a neighbour; the exact
graph supplied the location.

It is not free. That slice is the 15th presented range, behind nine "test evidence" pointers and
five other slices, so a model reading in order pays 27,641 bytes - against the baseline's 43,868
across 10 opened files (251,474 whole-file). A 1.6x reduction with the right validator named in the
leads, not the 7x of the local-method shape.

C, seeded from the file:line the issue names, hits the right *file* and never the right *range*: its
slices there are lines 12-37 and 140-250, on either side of the edit, for a 15,281-byte projection
and 27,189 bytes of reading. Being handed the location the reporter already knew is worth little;
`mergeInheritdocData` simply has no structural edge to the other half of the fix.

### 4. On a symbol-less data file, Map adds cost and finds nothing

`portable-ascii-62`: both map strategies miss on both backends. C cannot even be seeded - no task
term resolves to an indexed method, because the edit location is a `return [...]` data file with no
declarations at all (194 of 199 indexed portable-ascii files are symbol-less). B spends 1,199 bytes
of projection (11,700 with phpstan) to present `ASCII::to_transliterate`, `to_filename` and
`remove_invisible_characters` as seeds, then 10,763 bytes of ranges (60,927 with phpstan) that
cannot contain the answer: 1 wrong file on the structural backend, 4 on phpstan. The baseline finds
the file in 5 opens and 15,054 bytes, because grepping for `ä` is exactly the right move here.

Coverage is not the problem: the file *is* chunked, 8 file-segment chunks, and the segment holding
the German block (lines 2001-2400) is retrievable. It ranks **51st** for the issue text. This is a
ranking result, not an absence result, and the distinction is only visible because the harness
measures the deep rank instead of reading silence as "not there".

### 5. Both tempting fixes are refuted by the same table

- **Query formulation (probe D).** Replacing the prose query with the task's code vocabulary moves
  the data-file range from rank 51 to 15 - still outside the top 8 - and moves the cross-file range
  from 20 to **124**, turning a hit into a miss. A query change that wins one shape and destroys
  another is not an improvement.
- **Projection budget (probe E).** The owner default policy (20 files / 60 KB) changes no hit or
  miss anywhere. On the data-file replay it raises reading from 60,927 to 82,090 bytes for the same
  failure. The Loop's 6-file / 16 KB budget is not what loses a location.

## Falsification questions, answered

| question | answer from this evidence |
| --- | --- |
| current Map workflow materially reduces reading | **yes, and by how much depends on the shape**: 6.6-9.1x less source and 65x less tool output on the local-method task; 1.6x on the cross-file task; nothing at all on the data-file task |
| Map improves correctness but not cost | not observed; where Map hits, it hits cheaper - though the margin on the cross-file task is 1.6x, not an order of magnitude |
| Map adds cost without improving discovery | **yes, on symbol-less data/config work**: 1.2-11.7 KB of projection plus 10.8-60.9 KB of ranges that cannot contain the answer, and no hit |
| exact neighbour queries outperform ranked Search for some task shape | **partly**: exact *expansion from* a ranked seed found the cross-file location that ranking alone put at position 20, and test-callee expansion put the local-method answer in first place; standalone `callers`/`callees`/`impact` seeded from the issue's own file:line found nothing new |
| ranked Search is useful only after a narrower structural seed exists | **not supported**: on `portable-ascii-135` the raw description ranks the answer 2nd-3rd with no seed at all |
| current 6-file / 16 KB expansion is too broad or too narrow | **neither**: probe E changes no outcome and only adds bytes |
| an observation-scope fact is missing and causes unsafe absence inference | **no missing fact, one unconsumed one**: backend identity and the absence of `calls` relations are already in the index; no consumer reads them, so a structural-map miss looks like a code fact |
| no product change is justified | **for agent-map, correct.** The changes this evidence does support are consumption changes and subtractions - see the follow-up list |

## What the product actually is

The clearest structural result is not about Search at all. On the cross-file replay the verified
range sits at rank 20, outside any limit the Loop would sanely use, and the Loop finds it anyway -
because a rank-8 neighbour expands through `EditContextPlanner` to a direct caller. On the
local-method replay under phpstan the same thing happens one step earlier: the rank-1 lead is a
*test*, and its non-test callee is the fix location.

That is a different mental model from "Search is a location oracle":

```text
task text        -> HybridSearch          = seed generator, not an answer
seed             -> EditContextPlanner    = evidence refiner, exact and bounded
named range      -> real source read      = the only authority
```

Read that way, two of this experiment's negative results stop being surprising. Optimising Search
for final placement (probe D) attacks the wrong stage and cost one replay its hit. Widening the
projection budget (probe E) buys nothing because the answer was never being cut off - it was being
reached by expansion, and expansion was already in the budget.

It also suggests the product path is smaller than the CLI surface:

```text
PRODUCT PATH        Search -> Planner -> bounded reads
EXPERT / DIAGNOSTIC callers, callees, scope, impact, temporal
UNPROVEN            rank, and the automatic breadth of discover
OTHER CHANNEL       literal / data / config questions -> native text search
```

The last line is not a gap to close. `portable-ascii-62` is a task where the correct observation
channel is grep, and the existing agent-loop investigate skill already says to use `rg` when the map
cannot answer a literal/string/config/template question. That boundary held up; agent-map does not
need to become a general repository search engine to cover it.

## Per-capability decision

| capability | decision |
| --- | --- |
| `EditContextPlanner` (Loop policy) | **consume, unchanged.** It produced every map range hit in the experiment, including the cross-file one that ranking put at position 20. |
| ranked hybrid Search | **consume, unchanged, and treat it as a seed generator.** Useful for reaching a structural entry point and, on two of three replays, for naming the right validator; do not tune ranking from these three tasks, and do not switch its query formulation (probe D). |
| `MapReadinessInspector` | **consume, extend consumption (agent-loop side).** It already hands the index over, and recall already projects `backend`; what is missing is the consequence when the backend cannot answer graph questions. |
| exact `callers` / `callees` | **retain as model-invoked.** No replay justified an automatic consumer; their value in B came through the planner, which already uses them. |
| `impact` | **retain as model-invoked, and validate on one real task.** 15.6 KB text / 53 KB json, and no replay needed it - but no replay tested a change-risk question either. Next genuine shared-method change: run it once and record whether it produced a relevant location the planner and callers had not already supplied. Repeated "no" moves it out of the standard skill path. |
| `discover` | **retain, unproven, and the next rent question.** Measured cost 9.7-54 KB depending on format, and it is not merely a CLI: recall emits it automatically for tasks that name no files and no targets. No replay here activates it. The experiment it needs is one under-specified real task: did architecture discovery change the first useful read set? |
| `rank` | **subtraction candidate.** No automatic consumer, no skill mention, no replay that wanted a repository-wide importance ranking, ~4.7 KB for a top-10. That is API, CLI, documentation and maintenance surface with no demonstrated workflow effect in this stack. Not a deletion today - external consumers are not ruled out - but it should stop being retained by default. |
| `scope` | **retain as a cheap primitive.** Also unconsumed and unmentioned in any skill, but 1.3 KB for one method's exact call listing against 34 KB for `context` text. Unused plus duplicative plus expensive is a deletion candidate; unused plus substantially cheaper plus unique bounded evidence is not the same thing. If a future task needs callees without a projection, consume this rather than adding a command. |
| `query` / `related` / `file` / `changed` | **no decision - not measured.** These are the commands the agent-loop skills actually put in front of a model, and no strategy here stands in for them: A is grep, B and C are automatic. |
| temporal history / claims | **out of scope here**; none of the three tasks is a change-risk question. |
| a new `neighbours` command or a new observation fact | **not justified.** Nothing in the table needed a query the existing surfaces cannot express. |

Two asymmetries are worth carrying forward from that table. Unused is not one verdict: `rank` and
`scope` are both unconsumed, and only one of them is a subtraction candidate. And the surface this
experiment can speak about is narrower than the surface the skills teach - the automatic path is now
better understood than the path we explicitly hand to models, which is the wrong way round.

### Follow-ups this evidence supports

Ordered, each gated on what was actually measured. None of them is implemented here; keeping the
measurement separate from the changes it may justify is the point.

1. **agent-loop correctness (small, safe).** When the expander runs against a structural-only map,
   say so in the `skip`/blind-spot channel it already uses. A model that reads "no callers" from a
   map with zero call relations is inferring absence from a channel that cannot observe presence.
2. **Rent check on `discover`.** At the next genuinely under-specified real task, record whether the
   automatic architecture-discovery fact changed the first useful read set. If it did not, a large
   automatic projection can be reduced or removed - a bigger win than any ranking work.
3. **Skill-surface reduction, by observation not by benchmark.** On the next real investigation
   tasks, log `command used -> unique useful location gained? yes/no` for `query`, `related`, `file`,
   `changed`, `impact` and the history commands, and drop from the standard guidance whatever keeps
   answering no. Do not build a second benchmark for this.
4. **Freeze or retire `rank`** once external use is checked.
5. **Leave Search and `EditContextPlanner` alone.** No query tuning, no budget widening; both were
   tested and both made things worse or made no difference.
6. **Keep native text search as a first-class channel** for literal/data/config work instead of
   growing agent-map to cover it.

## Threats to validity

- **Three tasks, two repositories, one author's code.** Shapes were chosen first and graded against
  upstream fixes, but this is not a cohort.
- **The strategies are policies, not models.** They bound what a careful reader would do; a real LLM
  may grep better than policy A or waste the projection B gives it. Nothing here measures a model.
- **Baseline reading is modelled, not observed.** Whole-file and windowed numbers are both reported;
  the windowed one is used in the table and is the more favourable to the baseline.
- **phpstan ran without the target repositories' own dependencies installed**, which raises
  diagnostics (101 and 372) and may cost some relations. The structural/phpstan gap is therefore a
  lower bound on what the semantic backend adds.
- **The data-file replay's fix was authored by an earlier agent-loop dogfood run.** The location is
  verified by the upstream commit, but the task was already known to be solvable this way.
- **Model-invoked map navigation is not measured.** Strategy A is a grep/read baseline, not a model
  using `map query`/`related`/`file`. The comparison here is "automatic map projection versus
  repository-native search", not "map commands versus grep".
- **No token, turn or wall-clock telemetry** is claimed. Bytes and ranks are what the harness can
  observe honestly.

## Reproduction

```bash
composer install                     # phpstan/phpstan is required for the phpstan rows
tools/dogfood/run-replays.sh /tmp/agent-map-dogfood
```

The script clones the two upstream repositories, checks out each replay's base commit into its own
worktree, runs all three replays under both backends, and prints the evidence table. Individual runs:

```bash
php tools/dogfood/navigation-replay.php \
    --replay=tools/dogfood/replays/portable-ascii-135.json \
    --repo=/tmp/agent-map-dogfood/frozen/portable-ascii-135 \
    --artifacts=/tmp/agent-map-dogfood/artifacts/portable-ascii-135-phpstan \
    --backend=phpstan --json=/tmp/report.json
```
