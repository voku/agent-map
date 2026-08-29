# Hidden grading rubric — nav-substitution-01

Sealed before the MAP_FIRST arm runs. See `SEAL.txt`.

## What is graded

Semantic behaviour of the candidate, **not** patch identity. A candidate may
name the field, the property, the private helper and its internal sentinel
however it likes. It may add a constructor parameter or derive the value some
other way. What it may not do is break the provenance contract.

## The contract

An `AnalysisFingerprint` must record **which exact PHPStan package produced the
map**, and must **never claim a PHPStan package for a map that no PHPStan
produced**.

## Checks

| id | check | why |
|----|-------|-----|
| G1 | `toArray()` exposes a `phpstan_reference` key | the field has to be persisted, not just computed |
| G2 | a PHPStan-backed fingerprint records exactly `InstalledVersions::getReference('phpstan/phpstan')` | that is the point of the change |
| G3 | **a structural-only fingerprint never records the installed PHPStan reference** | discriminating case, see below |
| G4 | a fingerprint deserialized without `phpstan_reference` stays explicitly `unknown` | historical maps must not be back-filled from the current runtime |
| G5 | a recorded reference round-trips verbatim | reading a stored map must not consult the current runtime |

`GRADE=1` requires all five. Any failure is `GRADE=0`.

## G3, the discriminating case

The structural sentinel in G3 is read **from the production code path that
emits it**, not from a literal in the grader:

```php
$structuralVersion = (new StructuralOnlySemanticAnalyzer())
    ->analyse(sys_get_temp_dir(), [])
    ->phpStanVersion;
```

At base `b8ecad69c` that value is `'none'` (`src/Build/StructuralOnlySemanticAnalyzer.php`).

The trap is that the *same class* also has:

```php
public function backend(): string { return 'structural-only'; }
```

So `'structural-only'` is the **backend name**, and `'none'` is the
**version sentinel**. They live in the same 25-line file. An agent that arrives
via a broad text search for "structural" reads the first string it sees; an
agent that arrives by resolving `AnalysisFingerprint` and its actual producers
sees which value reaches the constructor.

A candidate that gates on `'structural-only'` compiles, type-checks and passes
the repository suite, but at runtime the branch never fires: the real value is
`'none'`, `phpstan/phpstan` *is* installed in the dev environment, and the
fingerprint gets stamped with the reference of a PHPStan that never ran. That
is provenance falsification, which is the exact failure the task exists to
prevent, so G3 is a hard failure and not a style note.

This is also why `repository validation PASS` was never evidence of
correctness for this pair.

## Grader validation (performed before sealing)

The grader was executed against two candidate trees assembled from git history:

| candidate | source | result |
|-----------|--------|--------|
| GOOD | the historical accepted fix, `dbbe666` | `OK (5 tests, 17 assertions)` |
| BAD | same patch with the sentinel rewritten to `'structural-only'` | `Tests: 5, Failures: 1` — G3 only |

The BAD candidate fails **only** G3, so the grader discriminates on the
provenance defect rather than on incidental differences.

## Scope limit

This grader is specific to task #1. It says nothing about navigation cost, and
a `GRADE=1` on both arms is the precondition for the cost comparison being
meaningful at all — not a result in itself.
