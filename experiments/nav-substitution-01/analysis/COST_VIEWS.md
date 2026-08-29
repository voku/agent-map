# Cost comparison — three views, kept separate

Only fill this in after **both** arms grade `GRADE=1`. If either arm is
`GRADE=0`, the cost numbers are not comparable and the pair is classified
under the decision tree in `../README.md` instead.

## Conventional baseline (frozen)

| metric | value |
|--------|-------|
| navigation calls | 7 |
| navigation bytes | 25,815 |
| source-read calls | 4 |
| source-read bytes | 7,781 |
| text-search calls | 2 |
| text-search bytes | 17,944 |
| largest single observation | 17,106 bytes, one broad `rg` |
| token usage | NOT_OBSERVABLE |

The 17,106-byte search is the specific target. The question for this pair is
not "does Map work" but: **does Map reach the same implementation evidence
without that search?**

## View A — direct navigation (strongest directly observed evidence)

| metric | Conventional | MAP_FIRST |
|--------|--------------|-----------|
| navigation calls | 7 | |
| navigation bytes | 25,815 | |
| source-read calls | 4 | |
| source-read bytes | 7,781 | |
| text-search calls | 2 | |
| text-search bytes | 17,944 | |

## View B — cold Map cost

    map-build bytes + map-nav bytes

Answers: *is Map worthwhile for a single isolated task starting from nothing?*

Map is allowed to lose here. A cold-build tax on a one-shot task is a real cost
and reporting it as a loss is the honest result.

## View C — steady-state Map cost

    map-nav bytes only

Answers: *once a repository map exists, does it replace exploratory reading?*

This is the realistic agent-workflow question and the more likely location of
actual product value.

**B and C are never averaged.** An average of cold and steady-state produces a
tidy number answering a question nobody asked.

## Map operation classification

Classify every Map operation individually:

| class | meaning |
|-------|---------|
| `USEFUL` | Map identified exact evidence and the agent acted on it |
| `REDUNDANT` | Map identified the evidence and the agent then ran the same `rg`/read anyway |
| `INCOMPLETE` | Map answered partially and a genuine follow-up was required |
| `MISLEADING` | Map pointed somewhere wrong or encouraged the wrong target |

| # | invocation | output bytes | class | note |
|---|-----------|--------------|-------|------|
| | | | | |

The distinction that matters:

- Map → exact evidence → edit = `USEFUL`
- Map → evidence → agent repeats normal discovery = `REDUNDANT`

`REDUNDANT` is a consumer/guidance finding, not necessarily an `agent-map`
defect. Keep that attribution separate.

`MISLEADING` deserves particular attention on this task. The target file
`src/Build/StructuralOnlySemanticAnalyzer.php` contains both `'structural-only'`
(the backend name) and `'none'` (the version sentinel) within 25 lines. If Map
surfaced the class but not which value actually reaches the
`AnalysisFingerprint` constructor, an agent can still pick the wrong string —
and would then be cheaper *and* wrong. Cost alone will not catch that; the
grader will.

## Conclusion (task shape #1 only)

This pair covers exactly one shape: **known class, small local provenance
change, focused tests.** The conclusion must be scoped to it, e.g.

- Map helps known-symbol local PHP changes, or
- Map adds overhead when the target is already obvious, or
- Cold Map loses, steady-state Map wins, or
- Map identifies the target correctly but Codex redundantly rereads it.

Not: *"agent-map saves X% tokens."* Model tokens are `NOT_OBSERVABLE` here and
one task could not support that claim even if they were.

**No `agent-map` product change is permitted before this conclusion is frozen.**
If the first Map run is ugly, first determine which of these it is — Map output,
Codex consumption, task shape, cold-start economics, or the measurement itself.
Tuning the instrument after seeing where the needle landed is an efficient way
to manufacture an encouraging benchmark.
