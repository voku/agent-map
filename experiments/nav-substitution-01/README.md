# nav-substitution-01 — does Map substitute for exploratory navigation?

A two-arm A/B on one historical `agent-map` task. The single manipulated
variable is **navigation policy**. Everything else — base commit, task text,
model, configuration, dependencies, validation — is held identical.

The measurable target is **navigation substitution**, not model-token savings.
Model tokens are `NOT_OBSERVABLE` in this harness and no claim will be made
about them.

## Task under test

| | |
|---|---|
| repository | `voku/agent-map` |
| base | `b8ecad69c6514514b40869e0a643b19fc019ebcf` |
| shape | known class, small local provenance change, focused tests |
| accepted fix (grading only) | `dbbe666` — *fix: persist exact PHPStan package provenance (#28)* |
| touched by accepted fix | `src/Index/AnalysisFingerprint.php`, `tests/AnalysisFingerprintTest.php` |

`dbbe666` is the **grading key**. It must not reach either arm. See integrity
criterion 8 — it is a child of the base and reachable from `origin/main`, so
this hole is currently open.

## State

| step | state |
|------|-------|
| 1. Conventional arm frozen | **done** — `arms/conventional/result.json` |
| 2. MAP_FIRST arm | **not run** — packet ready at `arms/map-first/TASK_PACKET.md` |
| 3. Integrity gate | prepared — `integrity/GATE.md` |
| 4. Hidden grading | **prepared and sealed** — `grader/` |
| 5. Cost comparison | template — `analysis/COST_VIEWS.md` |
| 6. Map substitution classification | template — `analysis/COST_VIEWS.md` |
| 7. Freeze conclusion for this shape | pending |

Steps 3–6 were built **before** the MAP_FIRST arm ran, on purpose. A grader
written after seeing the result is not a grader.

## Why the grader was built now rather than at step 4

The user's sequence grades after both arms exist. The *criteria* still have to
be fixed before the second arm runs, or the whole pair is retro-fittable. So
the grader is pre-registered and hashed in `grader/SEAL.txt`, and only
*executed* at step 4.

It has already been validated against two candidates assembled from git
history:

| candidate | result |
|-----------|--------|
| the historical accepted fix `dbbe666` | `OK (5 tests, 17 assertions)` |
| the same patch with the sentinel rewritten to `'structural-only'` | `Tests: 5, Failures: 1` — the discriminating check only |

So the grader passes the known-good implementation and fails the reported
Conventional shape on exactly one check, without collateral failures.

That is a property of the grader, **not** a verdict on the Conventional arm.
The real candidate patch was not carried into this session; until it is graded,
`independent_correctness` stays `PENDING`.

## Two things block the pair right now

**1. The Conventional arm is not actually frozen — it is remembered.**

Only the aggregate metrics survived into this session. The navigation log,
lock digest, candidate patch and its SHA, validation output, blind spots,
task text, and model/config did not. Integrity criteria 4 and 5 cannot be
evaluated, and the candidate cannot be graded. Everything in
`arms/conventional/result.json` marked `NOT_SUPPLIED_TO_THIS_SESSION` needs to
be copied out of the Codex run.

**2. Dependency reproducibility is broken by default and it is fixable now.**

`composer.lock` is `.gitignore`d and every requirement is a floating caret, so
two arms resolve independently. Under the pre-registered rule that makes
`INVALID_EXPERIMENT` the *expected* verdict on a foreseeable, preventable
cause. `pinned/composer.lock.pinned` fixes this for future pairs; it cannot
retroactively fix the current one, because the Conventional arm's lock is not
recorded here.

Fixing dependency reproducibility **before** spending the MAP_FIRST run is
cheaper than discovering it in the gate afterwards.

## Decision tree after the MAP_FIRST arm

```
MAP_FIRST run
  |
  +-- integrity fails
  |     -> repair experiment only, repeat the same pair
  |
  +-- candidate incorrect
  |     -> LOSS; inspect whether navigation omitted required evidence
  |
  +-- correct + Map navigation clearly cheaper
  |     -> first positive self-diagnostic evidence; move to task #2
  |
  +-- correct + approximately equal
  |     -> NEUTRAL for known-target changes; move to a different task shape
  |
  +-- correct + Map more expensive
        -> inspect: cold-build tax? oversized context? redundant rereads?
                    unnecessary Map calls?
        -> do NOT change the product yet
```

## Remaining programme (do not reorder, do not skip to repetition)

1. known-symbol local change — **this pair**
2. target discovery — behaviour known, implementation location not supplied
3. cross-file change — production target + caller/contract + tests
4. negative control — config / literal / docs / symbol-less PHP data, where the
   healthy result is *Map correctly stays out of the way*, and where MAP_FIRST
   making three semantic calls before falling back to `rg` is itself a finding
5. only then repeated trials per arm, compared on medians

Before the harness survives shapes 1–4, repetition mostly buys expensive
rediscovery of harness mistakes.

## Layout

```
arms/conventional/result.json      frozen baseline + preservation gaps
arms/map-first/TASK_PACKET.md      paste-ready packet for the one fresh run
pinned/composer.lock.pinned        candidate dependency pin + rationale
integrity/GATE.md                  9-criterion gate, run before grading
grader/RUBRIC.md                   what is graded and why
grader/AnalysisFingerprintGraderTest.php   sealed hidden grader
grader/grade.sh                    ./grade.sh <candidate-checkout> -> GRADE=0|1
grader/SEAL.txt                    pre-registration hashes
analysis/COST_VIEWS.md             views A/B/C + substitution classification
```
