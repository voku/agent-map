# Integrity gate — run immediately after the MAP_FIRST arm, before anything else

Do not proceed to grading or cost comparison until every row is settled.
A gate that is waved through here produces a number that looks like evidence
and is not.

| # | criterion | how to check | status |
|---|-----------|--------------|--------|
| 1 | base SHA identical | both arms report `b8ecad69c6514514b40869e0a643b19fc019ebcf` | |
| 2 | dependency lock SHA identical | `sha256sum composer.lock` from both arms | **AT RISK — see below** |
| 3 | different fresh Codex task | not a continuation of the Conventional thread | |
| 4 | same task text | byte-identical; diff the two prompts | **BLOCKED — Conventional task text not preserved into this session** |
| 5 | same model and config | recorded per arm | **BLOCKED — Conventional model/config not preserved into this session** |
| 6 | same validation | `composer ci`, run exactly once per arm | |
| 7 | Map actually used first | MAP_FIRST log shows a map call before any `source-read` or `text-search` | |
| 8 | no future-solution leakage | no arm read `dbbe666`, `origin/main`, or any descendant of the base | **AT RISK — see below** |
| 9 | no material unlogged navigation | per-call byte counts sum to the reported totals | **Conventional has a 90-byte residual — see below** |

## Criterion 2 — dependency drift is the likely failure, and it is foreseeable

`composer.lock` is `.gitignore`d and all requirements are floating carets, so
two independently resolved installs are unlikely to match. This is not a
hypothetical: it is the default behaviour of this repository.

Per the pre-registered rule, a differing lock digest classifies the pair as:

    INVALID_EXPERIMENT   (for strict A/B cost comparison)

and dependency reproducibility gets fixed before another pair is run — not
waved away because the code happened to work.

The fix is prepared: `../pinned/composer.lock.pinned`. It is a **candidate**
pin resolved in this session and is *not* known to equal what the Conventional
arm installed.

## Criterion 8 — history leakage is unclosed

The accepted fix is `dbbe666`, a direct child of the base and reachable from
`origin/main`. Any arm with full git history can reach the answer with one
`git log -- src/Index/AnalysisFingerprint.php`.

The Conventional arm's 17,106-byte broad `rg` is weak evidence that it did not
do this. Weak evidence is not the same as a closed hole, and the MAP_FIRST arm
has a stronger incentive to find a shortcut, not a weaker one.

Close it by stripping history for the run, or by requiring every git command to
be logged in the `git-history` category. Record which was done.

## Criterion 9 — the Conventional arm has an unattributed 90 bytes

    source_read_bytes   7,781
    text_search_bytes  17,944
    sum                25,725
    reported           25,815
    residual              +90

Either a third navigation category exists in the raw log, or one bucket total
is slightly off. 90 bytes changes no conclusion about a 17,106-byte search, but
it does mean the reported totals are not currently reconstructible from the
log, and criterion 9 cannot be marked satisfied until it is.

## Outcome

- All rows pass → proceed to grading.
- Any row fails → `INVALID_EXPERIMENT` for strict cost comparison. Repair the
  harness and repeat the pair. Do not grade a broken pair "just to see".
