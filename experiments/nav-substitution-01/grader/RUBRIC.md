# Hidden grading rubric — nav-substitution-01

Sealed before the MAP_FIRST arm runs. See `SEAL.txt`.

## What is graded

Semantic behaviour of the candidate, **not** patch identity. A candidate may
name the field, the property, the private helper and its internal sentinel
however it likes. It may add a constructor parameter or derive the value some
other way. What it may not do is break the provenance contract.

The original task did not prescribe a serialized key name. The grader therefore
discovers the persisted provenance key from the candidate's own PHPStan-backed
serialization instead of requiring the historical fix's `phpstan_reference`
name.

## The contract

An `AnalysisFingerprint` must record **which exact PHPStan package produced the
map**, and must **never claim a PHPStan package for a map that no PHPStan
produced**.

## Checks

| id | check | why |
|----|-------|-----|
| G1 | `toArray()` persists provenance beyond the historical four-key schema | the field has to be serialized, not just computed |
| G2 | one added provenance field records exactly `InstalledVersions::getReference('phpstan/phpstan')` for a PHPStan-backed fingerprint | that is the point of the change |
| G3 | **a structural-only fingerprint never records the installed PHPStan reference in that same field** | discriminating case |
| G4 | a fingerprint deserialized without the added field stays explicitly `unknown` | historical maps must not be back-filled from the current runtime |
| G5 | a recorded reference under the candidate's own persisted field round-trips verbatim | reading a stored map must not consult the current runtime |

`GRADE=1` requires all five. Any failure is `GRADE=0`.

## G3, the discriminating case

The structural sentinel in G3 is read **from the production code path that
emits it**, not from a literal in the grader:

```php
$structuralVersion = (new StructuralOnlySemanticAnalyzer())
    ->analyse(sys_get_temp_dir(), [])
    ->phpStanVersion;
```

At base `b8ecad69c` that value is `'none'`
(`src/Build/StructuralOnlySemanticAnalyzer.php`).

The trap is that the same class also exposes a different backend identity.
An agent that follows the wrong string can therefore gate the provenance logic
on a value that never reaches `AnalysisFingerprint`.

A candidate with that defect compiles, type-checks and passes the repository
suite, but a structural map gets stamped with the reference of a PHPStan that
never ran. That is provenance falsification, so G3 is a hard failure.

## Pre-MAP amendment

The first sealed grader hard-coded the historical serialization key
`phpstan_reference` despite this rubric's stated rule that field names were not
part of the task contract. The actual Conventional candidate, recovered before
MAP_FIRST ran, used `phpstan_package_reference`.

That exposed an unfair incidental failure: the grader would reject a
semantically valid alternative name before reaching G3. Because no MAP_FIRST
result had yet been observed, the grader was amended pre-treatment to discover
the candidate's provenance key by value. The discriminating G3 behavior is
unchanged. See the append-only SEAL-3 entry.

## Scope limit

This grader is specific to task #1. It says nothing about navigation cost.
A `GRADE=1` is a correctness gate, not evidence of navigation efficiency.
