# nav-substitution-01 — does Map substitute for exploratory navigation?

A two-arm self-diagnostic on one historical `agent-map` task. The intended
manipulated variable is **navigation policy**. Because the Conventional arm's
exact dependency lock bytes and Codex model/config are not recoverable, this
first pair is now explicitly a **DIAGNOSTIC_PILOT**, not a strict controlled
A/B.

The measurable target is navigation substitution and evidence relevance, not
model-token savings. Model tokens are `NOT_OBSERVABLE` in this harness.

## Task under test

| | |
|---|---|
| repository | `voku/agent-map` |
| base | `b8ecad69c6514514b40869e0a643b19fc019ebcf` |
| shape | known class, small local provenance change, focused tests |
| accepted fix (grading only) | `dbbe666` — *fix: persist exact PHPStan package provenance (#28)* |
| touched by accepted fix | `src/Index/AnalysisFingerprint.php`, `tests/AnalysisFingerprintTest.php` |

The grading key must never reach MAP_FIRST. Its run directory is therefore an
exported base tree with no `.git` history. Operator-only material lives outside
`arms/map-first/TASK_PACKET.md`.

## Current state

| step | state |
|------|-------|
| 1. Conventional arm frozen | **done** — exact task text, 7-call navigation log, candidate patch, blind spots and validation summary filed |
| 2. Conventional independent grading | **done: GRADE=0** — G3 only; see `arms/conventional/GRADE.md` |
| 3. MAP_FIRST arm | **not run** — packet ready for DIAGNOSTIC_PILOT mode |
| 4. Integrity gate | prepared — `integrity/GATE.md` |
| 5. MAP_FIRST independent grading | pending; sealed G1–G5 only |
| 6. Substitution analysis | pending — `analysis/COST_VIEWS.md` |
| 7. Freeze conclusion for this shape | pending |

The grader is frozen at **SEAL-3**. A pre-treatment fairness defect in the
original grader was repaired before MAP_FIRST: the rubric said field names did
not matter while the test hard-coded the historical key `phpstan_reference`.
SEAL-3 discovers the candidate's persisted provenance field by semantic value,
while leaving the structural-provenance discriminator unchanged. No grader
change is permitted after MAP_FIRST output is observed.

## Conventional result

The candidate digest was verified before grading:

    377abd0d7afbfdca007bd42fd23e6d1f9924d8149300cec33b141ff413ca1742

Result:

| check | result |
|-------|--------|
| G1 persisted provenance field | PASS |
| G2 PHPStan-backed exact installed reference | PASS |
| **G3 structural-only never claims installed PHPStan** | **FAIL** |
| G4 historical fingerprint stays `unknown` | PASS |
| G5 stored reference round-trips | PASS |

The failure is the intended discriminator. `StructuralOnlySemanticAnalyzer`
emits `phpStanVersion = 'none'`, while its backend name is
`'structural-only'`. The Conventional candidate gates on the backend name, so
a real structural fingerprint falls through and records the installed PHPStan
package reference. Its own focused test constructs the fingerprint with the
same wrong value, so candidate tests are green while provenance is false.

This turns the earlier lesson into observed evidence: **green candidate tests
were not correctness evidence for this task.**

## Conventional navigation baseline

| metric | value |
|--------|-------|
| navigation calls | 7 |
| navigation bytes | 25,815 |
| source reads | 4 calls / 7,781 bytes |
| text searches | 2 calls / 17,944 bytes |
| file listings | 1 call / 90 bytes |
| largest observation | 17,106-byte broad `rg` |
| token usage | NOT_OBSERVABLE |

The 90-byte residual is fully reconciled as the file-listing call. Two calls
were also spent on a guessed nonexistent test path and the filename search used
to recover from it.

More importantly, the Conventional arm reached relevant source and still chose
the wrong semantic value. That sharpens the MAP_FIRST question from "can Map
find the class?" to:

> Can Map supply the producer/value-flow context needed to distinguish the
> backend name from the `phpStanVersion` that actually reaches
> `AnalysisFingerprint`, while avoiding broad exploratory search?

## Second Conventional defect — finding only, not a grading change

While grading, a second defect was found:

```php
InstalledVersions::getReference('phpstan/phpstan') ?? 'unknown'
```

`getReference()` throws when the package is absent; it does not return `null`.
The fallback is therefore unreachable. At the historical base the repository
has a `structural-without-phpstan` CI consumer proof (`composer install --no-dev`
followed by `bin/agent-map build`), and the candidate would break it.

This finding is **not G6**. G1–G5 were sealed before MAP_FIRST and remain the
only correctness score for both arms. Adding a new check after observing a
Conventional failure would tune the instrument to the result.

It does establish a harness lesson: `composer ci` in the dev environment was a
validation subset, not the repository's complete CI contract.

## Pilot integrity status

The Conventional lock digest is known:

    e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e

but its exact `composer.lock` bytes are not recoverable from the transcript.
`pinned/composer.lock.pinned` was independently resolved from the same base and
has a different digest. Exact Codex model/reasoning/config may also be
unprovable.

Therefore pair #1 stays:

    DIAGNOSTIC_PILOT

This permits:

- independent correctness grading;
- navigation-sequence analysis;
- per-operation `USEFUL|REDUNDANT|INCOMPLETE|MISLEADING` classification;
- cold-vs-steady Map observations;
- a qualitative claim that Map supplied or failed to supply missing semantic
  context, if the navigation log demonstrates it.

It does **not** permit a causal byte-level claim that navigation policy alone
produced the measured delta.

## Live outcome matrix

Conventional is already wrong, so only two rows remain:

| Conventional | MAP_FIRST | interpretation |
|---|---|---|
| wrong | correct | potentially strong Map evidence, but only if the substitution log shows Map supplied the missing producer/value-flow evidence rather than MAP_FIRST getting lucky |
| wrong | wrong | shared reasoning/test-coverage gap; not a Map win |

The flattering `wrong / correct` row has the higher evidentiary burden. If
MAP_FIRST grades 1 after merely rereading the same source Conventional read,
that is luck. If Map explicitly carries it to the producer relation/value that
reaches the constructor, that is relevant substitution evidence.

## Future strict harness — pair #2 onward

Do not retrofit these rules into pair #1. Apply them before either arm of the
next pair runs:

1. **Common dependency snapshot:** both arms install the same committed/pinned
   lock artifact from the start.
2. **Frozen model/config:** record the exact Codex model, reasoning setting and
   task configuration before launching either arm.
3. **History isolation:** both arms receive the same exported base tree without
   descendants or remotes that reveal the historical fix.
4. **Identical task text and instrumentation:** navigation policy is the only
   intentional difference.
5. **Repository validation contract:** pre-register not only `composer ci` but
   every task-relevant CI consumer proof needed to claim "repository green".
   For task shapes touching structural/PHPStan boundaries, that includes the
   historical `structural-without-phpstan` no-dev proof. This validation surface
   must be fixed before either candidate is observed.
6. **Hidden correctness oracle:** sealed before treatment and never extended
   after one arm reveals a new defect.
7. **Cold and steady Map costs stay separate.** Never average them.

That makes pair #2 the first candidate for `STRICT_CONTROLLED_AB` rather than
asking pair #1 to become something its missing artifacts cannot support.

## Remaining programme

1. known-symbol local change — **this diagnostic pilot**
2. target discovery — behaviour known, implementation location not supplied
3. cross-file change — production target + caller/contract + tests
4. negative control — config / literal / docs / symbol-less PHP data, where the
   healthy result may be Map correctly staying out of the way
5. only then repeated fresh trials per arm and median comparisons

No `agent-map` product change is justified from pair #1 before MAP_FIRST has
run, been graded, and its substitution path has been classified. Otherwise the
benchmark becomes a product-tuning feedback loop disguised as evidence.

## Layout

```text
arms/conventional/result.json          frozen Conventional evidence and grade
arms/conventional/GRADE.md             independent G1–G5 result
arms/map-first/TASK_PACKET.md           agent-facing; safe to paste whole
arms/map-first/OPERATOR_CHECKLIST.md    operator-only; never paste
a nalysis/COST_VIEWS.md                 cold/steady views + substitution rubric
integrity/GATE.md                       integrity classification
grader/RUBRIC.md                        sealed semantic contract
grader/AnalysisFingerprintGraderTest.php
                                      hidden grader, frozen at SEAL-3
grader/grade.sh                         grader runner
grader/SEAL.txt                         append-only seals
pinned/composer.lock.pinned             future common dependency snapshot
```
