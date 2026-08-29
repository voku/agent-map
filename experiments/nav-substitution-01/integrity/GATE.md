# Integrity gate — run immediately after the MAP_FIRST arm, before anything else

Do not proceed to grading or cost interpretation until every row is settled.
This first pair is currently pre-classified as `DIAGNOSTIC_PILOT`; it may only
be upgraded to `STRICT_CONTROLLED_AB` if the missing strict-control evidence is
actually recovered before MAP_FIRST runs.

| # | criterion | how to check | current status |
|---|-----------|--------------|----------------|
| 1 | base SHA identical | both arms report `b8ecad69c6514514b40869e0a643b19fc019ebcf` | pending MAP_FIRST |
| 2 | dependency lock SHA identical | strict mode requires both arms to be `e465a590…` | **FAIL for strict / allowed only as pilot** |
| 3 | different fresh Codex task | not a continuation of the Conventional thread | pending MAP_FIRST |
| 4 | same task text | compare against `arms/conventional/task-text.txt` | **reference filed; pending MAP_FIRST** |
| 5 | same model and config | recorded per arm | **NOT_PROVABLE unless recovered** |
| 6 | same acceptance validation | both must pass the repository gate; post-mutation duplicate host validation is not part of navigation cost | pending MAP_FIRST |
| 7 | Map actually used first | MAP_FIRST log shows a map call before source-read/text-search | pending MAP_FIRST |
| 8 | no future-solution leakage | MAP_FIRST run directory contains no `.git`; no grading key in pasted packet | **closed by construction for MAP_FIRST** |
| 9 | no material unlogged navigation | per-call byte counts sum to reported totals | **PASS Conventional; pending MAP_FIRST** |

## Criterion 2 — dependency environment

The Conventional arm's generated lock digest is known:

    e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e

but the lock bytes were not preserved in the available transcript. The prepared
`pinned/composer.lock.pinned` was independently resolved at the same historical
base and hashes to `2504f36b…`; it therefore cannot establish an identical
dependency environment for this pair.

Consequences:

- if the original lock is recovered and verifies to `e465a590…`, strict mode
  remains possible;
- otherwise run MAP_FIRST on the prepared pin only as `DIAGNOSTIC_PILOT`;
- do not publish a causal byte-reduction percentage for this pair;
- every future pair must start both arms from the prepared pin.

## Criterion 4 — task text recovered

The exact Coding task section from the pasted Conventional transcript is filed
as `arms/conventional/task-text.txt` and copied into the agent-facing
`arms/map-first/TASK_PACKET.md`.

The MAP_FIRST prompt must use that text without paraphrase. This criterion is
not PASS until the actual MAP_FIRST prompt is checked, but the earlier filing
blocker is gone.

## Criterion 5 — model/config

If the Conventional Codex task page still exposes the exact model, reasoning
setting and configuration, freeze them and use them for MAP_FIRST.

If not, record `NOT_PROVABLE`. That permanently limits this pair to
`DIAGNOSTIC_PILOT`; do not infer a match.

## Criterion 6 — validation scope

The Conventional transcript shows successful `composer validate`, PHPUnit,
PHPStan and `composer ci`, with duplicate post-edit validation caused by the
host workflow. MAP_FIRST intentionally runs the repository gate once.

The measured navigation window ends before the first mutation, so duplicate
post-mutation validation is excluded from navigation calls/bytes. Criterion 6
therefore asks whether both candidates pass the same repository acceptance gate,
not whether the host repeated identical commands the same number of times.

This clarification is recorded before observing MAP_FIRST and must not be used
to hide a MAP_FIRST validation failure.

## Criterion 8 — history leakage

The accepted fix is reachable from descendants of the historical base. MAP_FIRST
must therefore receive an exported base tree with no `.git` directory. A
history breach is `INVALID`, not merely a pilot downgrade.

The Conventional arm predates this rule. Its filed navigation log contains only
the seven recorded repository-navigation calls and no git-history command. That
is evidence consistent with no leakage, but the absence of stripped history is
still a limitation of this first pair and belongs in the final pilot caveats.

## Criterion 9 — Conventional reconciled

The full filed log now reconciles exactly:

    source-read    7,781 bytes   4 calls
    text-search   17,944 bytes   2 calls
    file-listing      90 bytes   1 call
    -----------------------------------
    total         25,815 bytes   7 calls

The earlier 90-byte residual was the `file_listing` call, not missing data.

## Classification

`STRICT_CONTROLLED_AB`
: Criteria 1–9 all pass, including identical dependency lock and provable
  model/config. Comparative cost claims allowed, scoped to this task shape.

`DIAGNOSTIC_PILOT`
: Navigation integrity, task identity, grading and history isolation are sound,
  but dependency equality and/or model/config equality is not provable. Keep
  grading and qualitative substitution findings; do not make causal byte-level
  efficiency claims.

`INVALID`
: MAP_FIRST is not fresh, does not actually use Map first, leaks future history,
  has material unlogged navigation, or fails the acceptance task in a way that
  prevents meaningful grading. Repair the harness before another pair.

## Outcome order

1. classify integrity;
2. run the sealed grader on both candidate patches;
3. only then inspect cold/steady costs and Map substitution;
4. keep any conclusion scoped to this known-symbol local-change task;
5. use the prepared pin and frozen model/config from the start for the next pair.
