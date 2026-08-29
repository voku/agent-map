# Conventional arm — independent grading result

    GRADE = 0   (incorrect)

Graded against `grader/` at SEAL-3. Candidate verified by digest before grading:

    sha256(candidate.patch) = 377abd0d7afbfdca007bd42fd23e6d1f9924d8149300cec33b141ff413ca1742  ✓ matches

## Result

| check | result |
|-------|--------|
| G1 persisted provenance field beyond historical schema | PASS |
| G2 PHPStan-backed fingerprint records installed reference | PASS |
| **G3 structural-only never claims an installed PHPStan package** | **FAIL** |
| G4 historical fingerprint stays explicitly `unknown` | PASS |
| G5 recorded reference round-trips verbatim | PASS |

One failure, and it is the discriminating case. The SEAL-3 field discovery
worked as intended: the grader located `phpstan_package_reference` by its
semantic value and graded the candidate on behaviour, never on the name.

## The headline finding

Run in the same tree, same command:

    candidate's own test suite      OK (4 tests, 5 assertions)     4 / 4 pass
    hidden grader                   FAILURES! 1 of 5              G3 fails

The candidate is internally consistent and green. Its own
`testStructuralFingerprintHasNoPackageReference` constructs the fingerprint with
`'structural-only'` — the same wrong value its implementation gates on — so the
test encodes the misunderstanding rather than catching it.

This is the concrete demonstration of the lesson recorded before grading:
**green repository tests were insufficient to establish hidden task
correctness.** It is no longer an inference from the metrics; it is reproduced.

## Defect 1 — wrong sentinel (what G3 catches)

```php
$phpStanVersion === 'structural-only' ? 'none' : InstalledVersions::getReference(...)
```

`StructuralOnlySemanticAnalyzer` emits `phpStanVersion` `'none'`; the string
`'structural-only'` is its `backend()` name. Both literals live in the same
25-line file. The branch therefore never fires for a real structural map, and
the fingerprint gets stamped with the reference of a PHPStan that never ran.

    structural map, phpstan installed
      accepted fix   phpstan_reference          = 'none'
      candidate      phpstan_package_reference  = '13d6b4f347bad222da436580c8304fa6f83e6bd0'

## Defect 2 — throws when PHPStan is absent (found while grading, outside G1-G5)

```php
InstalledVersions::getReference('phpstan/phpstan') ?? 'unknown'
```

`getReference()` **throws** `OutOfBoundsException` for a package that is not
installed. It never returns `null`, so the `?? 'unknown'` fallback is
unreachable. The accepted fix guards with `isInstalled()` first, which is
exactly why that guard is there.

Reproduced with `phpstan/phpstan` removed from the installed set:

    accepted fix   OK    -> phpstan_reference = 'none'
    candidate      THROWS OutOfBoundsException: Package "phpstan/phpstan" is not installed

This is not a hypothetical environment. `.github/workflows/ci.yml` at the base
has a `structural-without-phpstan` job that runs `composer install --no-dev`
and then `bin/agent-map build`, which constructs an `AnalysisFingerprint` — so
this candidate breaks a CI job that exists at the base commit specifically to
protect structural-only operation.

**So `repository_validation = PASS` was narrower than it looked.** It records
`composer ci` in a PHPStan-installed environment. It does not cover the repo's
own no-dev consumer proof, which the candidate would have failed. That is worth
carrying into the harness: the arm's validation step is not the repository's
full gate.

Defect 2 is recorded as a finding, not as a sixth grader check. The grader was
sealed before MAP_FIRST and stays sealed; adding a check now that the
Conventional candidate is known to fail it would be exactly the instrument
tuning this experiment is set up to avoid. Both arms are graded on G1-G5 only.

## Prediction check

`result.json` recorded, before grading:

> "expectation": "Likely FAIL ... gates the structural sentinel on
> phpStanVersion === 'structural-only'; production emits 'none'."

The prediction held, on the predicted check, for the predicted reason. Recorded
here so the call is auditable rather than remembered generously.

## Grading environment

`phpstan/phpstan` cannot be downloaded in this container (the proxy refuses the
GitHub fetch), so grading ran in a reconstructed harness: real PHPUnit 11.5.56,
the base tree's `StructuralOnlySemanticAnalyzer` / `SemanticAnalysisResult` /
`SemanticAnalyzer`, the candidate's `AnalysisFingerprint` applied from
`candidate.patch`, and a synthesized `InstalledVersions` entry for
`phpstan/phpstan 2.2.9 @ 13d6b4f3…`.

The grader only consults `isInstalled()`, `getReference()` and
`getPrettyVersion()`, all served from that metadata in the normal way, and the
G3 failure is a pure logic consequence of `'none' !== 'structural-only'` — not
an artifact of the harness. Re-running `grader/grade.sh` against a real
`composer install` is still worth doing as confirmation when a lock is
available; it is not expected to change the result.

## Control results

| candidate | grade | notes |
|-----------|-------|-------|
| historical accepted fix `dbbe666` | 1 | 5/5, 34 assertions |
| **Conventional arm (real patch)** | **0** | G3 only; its own 4 tests pass |
| control: accepted fix with sentinel swapped to `'structural-only'` | 0 | G3 only |

The grader fails the Conventional candidate on the same single check as the
synthetic control, and passes the known-good implementation. It discriminates on
the provenance defect and nothing else.
