# Integrity gate — run immediately after the MAP_FIRST arm, before anything else

Do not proceed to grading or cost comparison until every row is settled.
A gate that is waved through here produces a number that looks like evidence
and is not.

| # | criterion | how to check | status |
|---|-----------|--------------|--------|
| 1 | base SHA identical | both arms report `b8ecad69c6514514b40869e0a643b19fc019ebcf` | |
| 2 | dependency lock SHA identical | both arms must be `e465a590…` | **PREFLIGHT — currently FAILS, see below** |
| 3 | different fresh Codex task | not a continuation of the Conventional thread | |
| 4 | same task text | byte-identical; diff against `arms/conventional/task-text.txt` | **PENDING FILING** |
| 5 | same model and config | recorded per arm | **NOT_PROVABLE unless recovered — see below** |
| 6 | same validation | `composer ci`, run exactly once per arm | |
| 7 | Map actually used first | MAP_FIRST log shows a map call before any `source-read` or `text-search` | |
| 8 | no future-solution leakage | run directory contains no `.git`; `git-history` log category empty | **CLOSED BY CONSTRUCTION — see below** |
| 9 | no material unlogged navigation | per-call byte counts sum to the reported totals | **PASS for Conventional — see below** |

## Criterion 2 — now a preflight, and it currently fails

`composer.lock` is `.gitignore`d and all requirements are floating carets, so
two independently resolved installs do not match. That is the default behaviour
of this repository, not bad luck.

The Conventional arm's lock is known by digest:

    e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e

so this stops being a post-hoc invalidation and becomes a cheap preflight
(`../arms/map-first/OPERATOR_CHECKLIST.md`, preflight 1). Checked before the
run, a mismatch costs nothing; checked after, it costs a Codex run.

**The preflight fails today.** `../pinned/composer.lock.pinned` was resolved
independently and hashes to `2504f36b…`. A SHA-256 cannot be inverted into a
lock file, so the digest can only *verify* a lock — it cannot reproduce one.
The Conventional arm's actual `composer.lock` has to be filed at
`../arms/conventional/composer.lock`.

Until it is, the choice is: file the real lock and keep the strict pair, or
accept a known-different dependency set and drop to diagnostic pilot.

## Criterion 8 — closed by construction

The accepted fix `dbbe666` is a direct child of the base and reachable from
`origin/main`: one `git log -- src/Index/AnalysisFingerprint.php` reaches the
answer.

Both options were available — strip history, or permit history and audit the
log. **Stripping is now mandatory** (`OPERATOR_CHECKLIST.md`, preflight 2). The
run directory is a `git archive` export of the base tree with no `.git`, so the
descendants are not reachable at all.

Auditing would have left the temptation in place and made the criterion depend
on the arm's own honesty about its logging. Removing the history removes both
the temptation and the audit burden. The `git-history` log category is retained
so that an attempt is visible rather than silent.

The Conventional arm predates this rule. Its 17,106-byte broad content search
is consistent with not having taken the shortcut, but that is circumstantial,
and it is recorded as circumstantial.

## Criterion 9 — resolved, PASS

The 90-byte residual was a missing category, not a measurement error:

    source-read    7,781    4 calls
    text-search   17,944    2 calls
    file-listing      90    1 call    rg --files tests | rg 'Fingerprint|IndexTest'
    ----------------------------------
    total         25,815    7 calls

Both bytes and calls reconcile exactly. The MAP_FIRST packet now logs
`file-listing` as its own category so the arms stay comparable.

## Criterion 5 — model and config, the one genuinely open field

Everything else about the Conventional arm is recoverable from the Codex run.
Its model and configuration are recoverable only if the task page still exposes
them.

If it does: freeze them and use exactly those for MAP_FIRST.

If it does not: record `NOT_PROVABLE`. Do not assume they matched, and do not
quietly drop the criterion.

## Two classifications, not one

The original rule collapsed everything into `INVALID_EXPERIMENT`. That is right
for a *comparative claim* and wrong for the *run*, which still carries real
information.

**STRICT_CONTROLLED_AB** — every criterion passes. Cost differences are
attributable to navigation policy. Comparative claims permitted, scoped to this
task shape.

**DIAGNOSTIC_PILOT** — criterion 5 is `NOT_PROVABLE`, or criterion 2 fails.
Grading results, the substitution classification, and every qualitative finding
stay valid. Byte-level comparative claims do not. Keep the run as pilot
evidence; do not erase it, and do not upgrade its claims.

**INVALID** — criterion 3, 6, 7 or 9 fails, or criterion 8 is breached. The arm
did not measure what it claims. Repair and repeat the pair.

## Outcome

- All rows pass → `STRICT_CONTROLLED_AB`, proceed to grading.
- Only 2 or 5 fails → `DIAGNOSTIC_PILOT`, proceed to grading, restrict the claims.
- Anything else fails → `INVALID`. Repair the harness and repeat the pair.
  Do not grade a broken pair "just to see".
