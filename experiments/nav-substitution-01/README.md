# nav-substitution-01 — does Map substitute for exploratory navigation?

A two-arm self-diagnostic on one historical `agent-map` task. The intended
manipulated variable is **navigation policy**. Because the Conventional arm's
exact dependency lock bytes and Codex model/config are not recoverable, this
first pair is explicitly a **DIAGNOSTIC_PILOT**, not a strict controlled A/B.

The pilot is complete and closed.

Final verdict:

    PILOT_SHARED_FAILURE

Both candidates graded `0`. The accepted MAP_FIRST arm passed the experiment
integrity gate, but its required cold self-map failed under the host's 128M
memory limit before producing an index. MAP_FIRST therefore supplied no useful
navigation evidence and the candidate was completed through logged fallback
search/read operations.

The measurable target is navigation substitution and evidence relevance, not
model-token savings. Model tokens remain `NOT_OBSERVABLE` in this harness.

## Task under test

| | |
|---|---|
| repository | `voku/agent-map` |
| base | `b8ecad69c6514514b40869e0a643b19fc019ebcf` |
| shape | known class, small local provenance change, focused tests |
| accepted fix (grading only) | `dbbe666` — *fix: persist exact PHPStan package provenance (#28)* |
| accepted-fix files | `src/Index/AnalysisFingerprint.php`, `tests/AnalysisFingerprintTest.php` |
| experiment mode | `DIAGNOSTIC_PILOT` |
| final verdict | `PILOT_SHARED_FAILURE` |

The grading key never reached MAP_FIRST before freeze. Its run directory was an
exported historical tree with no `.git` history, and the sealed grader and
Conventional comparison evidence remained inaccessible until the candidate,
navigation log, validation evidence and patch digest were frozen.

## Final state

| step | state |
|---|---|
| 1. Conventional arm frozen | **done** |
| 2. Conventional independent grading | **done: GRADE=0** |
| 3. first MAP_FIRST attempt | **invalid**, retained only as harness evidence in `arms/map-first/ATTEMPT-01-INVALID.md` |
| 4. accepted MAP_FIRST arm | **done, integrity VALID** |
| 5. MAP_FIRST independent grading | **done: GRADE=0** |
| 6. substitution analysis | **done** |
| 7. task-shape conclusion | **frozen: PILOT_SHARED_FAILURE** |
| 8. further MAP_FIRST retries for pair #1 | **closed** |

The accepted MAP_FIRST result is filed at:

    arms/map-first/RESULT.md

The grader remains frozen at **SEAL-3**. No grading rule was changed after a
MAP_FIRST candidate became observable.

## Correctness

### Conventional

Candidate digest:

    377abd0d7afbfdca007bd42fd23e6d1f9924d8149300cec33b141ff413ca1742

| check | result |
|---|---|
| G1 persisted provenance field | PASS |
| G2 PHPStan-backed exact installed reference | PASS |
| G3 structural-only never claims installed PHPStan | **FAIL** |
| G4 historical fingerprint stays `unknown` | PASS |
| G5 stored reference round-trips | PASS |

Conventional therefore grades `0`.

Its candidate tests were green but encoded the same semantic misunderstanding
as the implementation. This is direct evidence that repository/candidate-green
was not sufficient correctness evidence for this task.

### MAP_FIRST

Candidate patch digest:

    7a8c172d324a3462445ca0ed730da00d639d446e5e2a1d57d70020d011659d54

Dependency lock digest:

    2504f36bb16a168111aa309107c0460fc9c22b86618fca6527c3d28f66c639f6

| check | result |
|---|---|
| G1 persisted provenance field | PASS |
| G2 automatic exact installed reference | **FAIL** |
| G3 explicit structural-only no-PHPStan representation | **FAIL** |
| G4 historical missing provenance is explicit `unknown` | **FAIL** |
| G5 stored exact-reference round-trip | **FAIL** |

MAP_FIRST therefore also grades `0`.

The candidate was frozen before hidden grading and was not repaired afterwards.

## Integrity

Accepted MAP_FIRST Phase A integrity is **VALID**:

- no unlogged navigation;
- no manual telemetry reconstruction;
- no pre-freeze hidden grader/Conventional evidence exposure;
- no future implementation/history inspection;
- no post-freeze candidate mutation;
- no grading evidence influenced implementation.

The earlier invalid attempt remains documented because it exposed useful harness
failures, but its candidate and measurements are not used as the accepted pair
result.

## Navigation comparison

| metric | Conventional | MAP_FIRST |
|---|---:|---:|
| navigation calls | 7 | 11 |
| navigation bytes | 25,815 | 30,103 |
| source-read calls | 4 | 7 |
| source-read bytes | 7,781 | 23,277 |
| text-search calls | 2 | 2 |
| text-search bytes | 17,944 | 6,339 |
| file-listing calls | 1 | 0 |
| file-listing bytes | 90 | 0 |
| map-build calls | 0 | 1 |
| map-build bytes | 0 | 402 |
| map-navigation calls | 0 | 1 |
| map-navigation bytes | 0 | 85 |

MAP_FIRST used fewer text-search bytes and no file-listing call, but the failed
Map path forced substantially more direct source reading. Overall it used four
more navigation calls and returned 4,288 more navigation bytes.

These are **descriptive pilot measurements only**. They are not a causal claim
about navigation policy because the original Conventional lock bytes and exact
model/config equality are not recoverable/provable, and both candidates failed
independent correctness grading.

## Map substitution result

The required self-map build was:

```bash
bin/agent-map build --root=. --paths=src,tests --out=.agent-map/php-symbols.json
```

It reached a definitive exit `1` after the PHPStan parallel worker exhausted the
configured 128M memory limit. No usable index was produced.

The subsequent query:

```bash
bin/agent-map query AnalysisFingerprint --index=.agent-map/php-symbols.json
```

also failed because the index did not exist. It is classified `INCOMPLETE`: it
displaced no implementation discovery.

No Map operation was `USEFUL`, `REDUNDANT`, or `MISLEADING` in the accepted
run. Correctness attribution to Map is therefore `NOT_APPLICABLE`.

Cold Map cost:

    487 bytes = 402 build + 85 navigation

Steady-state Map navigation cost:

    85 bytes

The nominal steady-state query delivered no navigation value because there was
no usable index. Cold and steady numbers stay separate and are never averaged.

## Validation finding

The accepted MAP_FIRST run captured the final `composer ci` status atomically.
It exited `255`: PHPUnit exhausted the same 128M process memory limit while
`AgentMapSelfDogfoodTest` decoded the large structural cache left by the failed
self-map build.

This is a narrow dogfood/environment finding, not a grading amendment. The
sealed G1–G5 contract remains unchanged.

`composer ci` is also not assumed to represent every historical repository CI
consumer proof. Pair #1 already established that task-relevant consumer proofs
must be pre-registered before future strict pairs begin.

## Pilot conclusion

For this exact **known-symbol / local-provenance** task shape:

> MAP_FIRST did not establish a correctness or substitution advantage because
> the required cold self-map failed before producing a usable index. The arm
> then paid the failed Map cost, fell back to conventional navigation, and
> shared the Conventional arm's overall incorrect outcome.

That is a valid `PILOT_SHARED_FAILURE` result.

It does **not** establish that Map generally helps or harms coding-agent work.
It also does not justify tuning `agent-map` from this historical task. Doing so
would turn the benchmark into a product-feedback loop after observing treatment
results.

## Second Conventional defect — finding only

A separate grading-time finding remains recorded but is not part of G1–G5:

```php
InstalledVersions::getReference('phpstan/phpstan') ?? 'unknown'
```

`getReference()` throws when the package is absent, so the fallback is not
reachable. The historical repository had a structural-without-PHPStan consumer
proof that would expose that problem.

This was deliberately not added as G6 after observing the Conventional
candidate.

## Strict pair #2 requirements

Pair #1 is finished. Pair #2 must be the first candidate for
`STRICT_CONTROLLED_AB` and must freeze the harness before either arm runs:

1. **Common dependency snapshot** for both arms from the start.
2. **Frozen model/config** recorded before launching either arm.
3. **History isolation** for both arms from the same exported base tree.
4. **Identical task text and instrumentation**; navigation policy is the only
   intentional difference.
5. **Pre-registered repository validation contract**, including every
   task-relevant consumer proof needed to claim repository-green.
6. **Hidden correctness oracle** sealed before treatment and never extended
   after observing an arm.
7. **Cold and steady Map costs remain separate.**
8. **No product tuning from pair #1 before pair #2 is frozen.**

## Remaining programme

1. known-symbol local change — **complete: DIAGNOSTIC_PILOT / PILOT_SHARED_FAILURE**
2. target discovery — next strict pair candidate
3. cross-file change — production target + caller/contract + tests
4. negative control — config / literal / docs / symbol-less PHP data
5. only then repeated fresh trials per arm and median comparisons

## Layout

```text
arms/conventional/result.json          frozen Conventional evidence and grade
arms/conventional/GRADE.md             independent Conventional G1–G5 result
arms/map-first/TASK_PACKET.md           agent-facing MAP_FIRST contract
arms/map-first/ATTEMPT-01-INVALID.md    rejected harness attempt
arms/map-first/RESULT.md                accepted MAP_FIRST result and final verdict
arms/map-first/OPERATOR_CHECKLIST.md    operator-only; never paste
analysis/COST_VIEWS.md                  cold/steady + substitution rubric
integrity/GATE.md                       integrity contract
grader/RUBRIC.md                        sealed semantic contract
grader/AnalysisFingerprintGraderTest.php
                                      hidden grader, frozen at SEAL-3
grader/grade.sh                         grader runner
grader/SEAL.txt                         append-only seals
pinned/composer.lock.pinned             reproducible pilot/future snapshot
```
