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
| file-listing calls | 1 |
| file-listing bytes | 90 |
| largest single observation | 17,106 bytes, one broad `rg` |
| token usage | NOT_OBSERVABLE |

Its 7 calls, in order:

| # | category | what | bytes |
|---|----------|------|-------|
| 1 | text-search | `rg AnalysisFingerprint` | 838 (derived) |
| 2 | source-read | the target file | — |
| 3 | source-read | a guessed test path that does not exist | — |
| 4 | source-read | `AgentMapBuilder` | — |
| 5 | file-listing | `rg --files tests \| rg 'Fingerprint\|IndexTest'` | 90 |
| 6 | source-read | `AgentMapIndexTest` | — |
| 7 | text-search | broad `rg` over InstalledVersions / getReference / phpstan_version / structural | 17,106 |

Two of the seven calls (3 and 5) are a miss and its recovery. That is a second
substitution target alongside the 17 KB search, and a cheaper one to win.

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

## Where this pair now sits in the matrix

**Conventional = GRADE 0** (`../arms/conventional/GRADE.md`). So only the bottom
two live rows remain reachable:

| Conventional | MAP_FIRST | reachable? |
|---|---|---|
| wrong | correct | **live** — the flattering cell |
| wrong | wrong | **live** — shared reasoning/test gap, not a Map result |
| correct | * | ruled out |

That makes the substitution log decisive rather than supplementary. If MAP_FIRST
grades 1, the claim only holds if the log shows Map actually carried it to the
producer relation — that `phpStanVersion` reaching the constructor is `'none'`,
not the `'structural-only'` backend name sitting three lines away. If it got
there by rereading the file the Conventional arm also read, that is luck, and
the honest write-up says so.

Note also that the Conventional arm reached the right *file* and still picked
the wrong *string*. Locating the class was never the hard part of this task, so
"Map found the class" is not evidence of anything here.

## Outcome matrix — read this before reading any byte count

| Conventional | MAP_FIRST | interpretation |
|---|---|---|
| correct | correct | compare navigation costs normally |
| wrong | correct | potentially strong Map evidence — **but only if** the substitution classification shows Map supplied the missing semantic context. If MAP_FIRST got it right for an unrelated reason, this is luck, not evidence. |
| correct | wrong | MAP_FIRST lost required evidence; inspect what bounded context omitted |
| wrong | wrong | the task exposes a shared reasoning or test-coverage gap, not a Map result |
| integrity fails | any | no strict comparative claim; see `../integrity/GATE.md` |

The `wrong / correct` cell is the one to be most careful with, because it is the
most flattering. It is also plausible here: the Conventional arm spent 17,106
bytes on a broad search and still appears to have picked the wrong sentinel, so
MAP_FIRST has two independent ways to win — avoid the search (efficiency) and
follow the producer relation far enough to see which value actually reaches the
constructor (correctness).

If both happen, the finding is that the narrower evidence was simultaneously
cheaper *and* more relevant. That is a stronger claim than a byte reduction, and
precisely because it is stronger it needs the substitution log to support it,
not just the two grades.

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
