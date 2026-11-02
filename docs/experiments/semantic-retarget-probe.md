# Semantic retarget probe (bounded, read-only experiment)

Inspired by ii wish/kgdiff (https://github.com/iiwish/kgdiff), this experiment tests one question: does grouping related relation additions/removals into a clearly UNVERIFIED retarget candidate improve review of real agent-map evolution beyond the existing history diff?

This does NOT introduce a new diff engine. agent-map history diff remains the owner of structural facts. The probe consumes its complete JSON output, compares only relation evidence with the same source ID, relation kind, resolution, receiver and result types, and reports one candidate if there is exactly one removed and one added relation with different opaque target strings. Multiple possible pairings remain ambiguous. It never guesses a rename, merges unrelated source IDs, or silently accepts truncated input.

## Reproducible CI evidence

The experimental .github/workflows/semantic-retarget-probe.yml workflow runs in pull requests modifying its experimental files or by manual dispatch once on the default branch. It:

1. Executes controlled tests for one-to-one pairings, ambiguous pairings, grouped targets, event order, and truncated-input failure.
2. Selects the newest merged Git tag with real src/ changes relative to the PR head, falling back to the repository root commit; fails rather than fabricating evolution.
3. Uses the PR revision's agent-map implementation to build canonical before/after maps from real Git revisions with PHPStan enrichment.
4. Runs the existing history diff command and experimental classifier twice, checking byte-identical classifier output.
5. Uploads map snapshots, relation companions, the unchanged diff, inferred candidates, and exact revision provenance.

The workflow requests contents: read only. No external npm dependency, token, LLM, write permission, remote knowledge store, or automatic lifecycle authority is required. It is not a required production gate.

## Local use

    php -l tools/temporal-retarget-probe.php
    php tools/temporal-retarget-probe.php --self-test
    php bin/agent-map history diff --before=/tmp/before.json --after=/tmp/after.json --format=json --limit=250000 > /tmp/history-diff.json
    php tools/temporal-retarget-probe.php /tmp/history-diff.json > /tmp/retarget-evidence.json

## Interpret and stop

Zero real-history candidates is a measured negative result, not a failure. More than zero does NOT prove an actual retarget: independent edits in one source method can resemble a retarget. Grouped target_ids are opaque comma-joined strings from the existing owner output, never flattened into independently asserted edges. No source call-site identity, rename, split/merge, authorization, graph invertibility, architectural improvement, or safety is established.

Before proposing a production API, manually classify actual candidates against Git source diffs, measure precision and ambiguity, and compare reviewer effort against existing history diff reports. Delete the experiment if the signal is not demonstrably useful; do not add a new runtime dependency merely because the results look plausible.


## Frozen historical corpus (selected before execution)

The first real-history run compared 0.22.5 with the PR head and found 17 structural events and **zero** retarget candidates. To test whether that was merely an uninformative adjacent pair, compare three fixed, non-overlapping release intervals, selected from publicly available source-changing commits before observing any probe output:

| Case | Before commit | After commit | Motivation |
| --- | --- | --- | --- |
| 0.19.0 → 0.20.0 | `555c3ad40cb8023444695219fbfb79a119767f46` | `96bffea331cec063adba37e6659e5f3760202f9b` | 11 changed src/ files across 12 commits; rename/removal work |
| 0.21.0 → 0.22.0 | `52c901f42f338bb6c1f29d5512873b05a751cdba` | `91e631249f3e3bcfd8b5783f600f2a6d8818cab2` | 2 changed src/ files across 10 commits; owner preparation and graph mechanics |
| 0.22.0 → 0.22.5 | `91e631249f3e3bcfd8b5783f600f2a6d8818cab2` | `5e5b179176ce8b9fa961c795daf84d1c692bc064` | 43 changed src/ files across 41 commits; real broad implementation history |

All endpoints are pinned exact Git commit SHAs (not moving branches or annotated tag objects). Each independent matrix job builds both historical source revisions using the same current PR build implementation, emits the original `history diff` plus candidate evidence, and checks repeated output parity. This is a **measurement corpus**, not a set of guaranteed positive retargets.

Acceptance for usefulness requires a candidate tied to independently inspectable real source evolution, and improvement versus the existing event report; zero candidates across the corpus is evidence in favour of **not** adding the experimental classifier to production.
