# MAP_FIRST result — nav-substitution-01

## Verdict

`PILOT_SHARED_FAILURE`

Both frozen candidates graded `0`. MAP_FIRST Phase A integrity was valid, so this is the accepted pair-level diagnostic-pilot result rather than an invalid arm.

The required cold self-map failed before producing an index because the PHPStan worker exhausted the configured 128M memory limit. MAP_FIRST therefore obtained no useful Map navigation evidence and completed the implementation through fully logged fallback search/read operations.

## Correctness

| arm | grade |
|---|---:|
| Conventional | 0 |
| MAP_FIRST | 0 |

MAP_FIRST sealed-grader result: 5 tests, 18 assertions, 4 failures.

- G1 PASS — serialized fingerprint added a persisted field.
- G2 FAIL — newly constructed PHPStan-backed fingerprint did not automatically capture the installed package reference.
- G3 FAIL — structural-only serialization did not expose the grader's explicit no-PHPStan marker.
- G4 FAIL — historical missing provenance exposed `null` instead of explicit `unknown`.
- G5 FAIL — exact-reference round-trip could not be established because the grader could not identify the provenance field from a newly constructed PHPStan fingerprint.

The candidate was frozen before grading and was not repaired afterwards.

## Integrity

Phase A integrity: `VALID`

- unlogged navigation: no
- manual telemetry reconstruction: no
- pre-freeze hidden evidence exposure: no
- post-freeze candidate modification: no
- future implementation/history inspection: no
- grading evidence influencing implementation: no

All repository observations were written by the navigation wrapper. Detached host executions were polled through their original sessions until final exit.

## Navigation

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

This remains a `DIAGNOSTIC_PILOT`; the observed delta is descriptive only. It is not a strict causal navigation-policy comparison because the original Conventional lock bytes and exact model/config equality are not recoverable/provable.

## Map operations

### Cold self-map build

```text
bin/agent-map build --root=. --paths=src,tests --out=.agent-map/php-symbols.json
```

- exit: 1
- bytes: 402
- lines: 5
- duration: 126,653 ms
- result: PHPStan parallel worker exhausted the configured 128M memory limit
- usable index produced: no
- retry: none

### Query

```text
bin/agent-map query AnalysisFingerprint --index=.agent-map/php-symbols.json
```

- exit: 1
- bytes: 85
- classification: `INCOMPLETE`
- displaced work: none; the query established only that the index did not exist

No Map operation was `USEFUL`, `REDUNDANT`, or `MISLEADING`.

Cold Map cost: 487 bytes.

Steady-state Map navigation cost: 85 bytes, but the query delivered no navigation value because no index existed.

## Fallbacks

Text searches:

1. `rg -n AnalysisFingerprint src tests` — 838 bytes
2. `rg -n 'InstalledVersions|getReference|structural-only|structural_only|phpstanVersion|new AnalysisFingerprint' src tests` — 5,501 bytes

Seven source reads totalled 23,277 bytes and covered the fingerprint, builder construction/backend selection, structural-only producer, semantic analyzer contract, and focused tests.

## Validation

- `composer install --prefer-dist --no-interaction`: PASS
- `composer ci`: FAIL, final exit 255 captured
- failure: PHPUnit exhausted the 128M process memory limit while `AgentMapSelfDogfoodTest` decoded the large structural cache left by the failed self-map build
- full `composer ci` reruns: none
- source/test `git diff --no-index --check`: expected difference status 1, no whitespace errors
- sealed grader: `GRADE=0`

`composer ci` is not assumed to represent every historical repository CI consumer proof.

## Candidate

Candidate patch SHA-256:

```text
7a8c172d324a3462445ca0ed730da00d639d446e5e2a1d57d70020d011659d54
```

Dependency lock SHA-256:

```text
2504f36bb16a168111aa309107c0460fc9c22b86618fca6527c3d28f66c639f6
```

Changed files, derived from the frozen patch:

- `src/Index/AgentMapBuilder.php`
- `src/Index/AnalysisFingerprint.php`
- `tests/AgentMapBuilderTest.php`
- `tests/AgentMapIndexTest.php`

## Result

For this exact known-symbol / local-provenance task shape, MAP_FIRST established no correctness or substitution advantage because its required self-map failed before producing a usable index. The arm then paid the cold Map failure cost and fell back to conventional navigation, ultimately sharing the Conventional arm's overall incorrect outcome.

The narrow dogfood finding is that, under this 128M host limit, a failed cold self-map can both force a more source-read-heavy fallback path and leave a structural cache large enough to affect later validation. That observation is not generalized beyond this task/environment and does not justify tuning `agent-map` from this pair.

## Next decision

Freeze this shared-failure conclusion and move to strict pair #2 without tuning Map from this task.
