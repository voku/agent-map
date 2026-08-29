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
| 1. Conventional arm frozen | **done, evidence recovered** — `arms/conventional/result.json` |
| 2. MAP_FIRST arm | **not run — blocked on preflight 1** — `arms/map-first/OPERATOR_CHECKLIST.md` |
| 3. Integrity gate | prepared — `integrity/GATE.md` |
| 4. Hidden grading | **Conventional graded: GRADE=0** — `arms/conventional/GRADE.md`. MAP_FIRST pending. |
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
`arms/conventional/result.json` records `FAIL` as an explicit, auditable
*prediction* so it can be checked against the eventual result — but
`independent_correctness` stays `PENDING` until `grade.sh` runs against the
real candidate patch (`377abd0d…`), which has to be filed first.

A predicted failure is not a failure. Recording the prediction before grading
is what makes it worth anything.

## What is recovered, and what still blocks the run

Most of the Conventional arm was recoverable from the Codex run. Recovered:
base SHA, lock digest `e465a590…`, candidate patch digest `377abd0d…`, the
ordered 7-call navigation sequence, and the resolution of the 90-byte residual
— it was a missing `file-listing` category, so **integrity criterion 9 now
passes** and the totals reconcile exactly on both bytes and calls.

Two blockers remain, one hard and one classifying.

**1. HARD — the lock preflight fails.**

The Conventional arm installed a lock hashing to `e465a590…`. The pin committed
here hashes to `2504f36b…`. A SHA-256 verifies a lock but cannot reproduce one,
so the arm's actual `composer.lock` must be filed at
`arms/conventional/composer.lock`. Until then MAP_FIRST must not run: the whole
value of turning this into a preflight is that a mismatch costs nothing before
the run and a Codex run after it.

**2. CLASSIFYING — model and config may be unrecoverable.**

This is the one field the Codex output does not carry. If the task page still
exposes it, freeze it. If not, criterion 5 is `NOT_PROVABLE` and the pair is a
`DIAGNOSTIC_PILOT` rather than a `STRICT_CONTROLLED_AB` — grading and the
substitution classification stay valid, byte-level comparative claims do not.
The run is kept as pilot evidence, not erased and not upgraded.

Also fixed: history leakage is now closed by construction rather than by audit.
The arm gets a `git archive` export of the base tree with no `.git`, so
`dbbe666` is unreachable instead of merely off-limits.

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
arms/conventional/result.json      frozen baseline, recovered evidence, filing list
arms/map-first/TASK_PACKET.md      agent-facing; safe to paste whole
arms/map-first/OPERATOR_CHECKLIST.md   operator-only; holds the grading key, NEVER paste
pinned/composer.lock.pinned        candidate dependency pin + rationale
integrity/GATE.md                  9-criterion gate, run before grading
grader/RUBRIC.md                   what is graded and why
grader/AnalysisFingerprintGraderTest.php   sealed hidden grader
grader/grade.sh                    ./grade.sh <candidate-checkout> -> GRADE=0|1
grader/SEAL.txt                    pre-registration hashes
analysis/COST_VIEWS.md             views A/B/C + substitution classification
```
