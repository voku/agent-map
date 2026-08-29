# MAP_FIRST — operator checklist

**Never paste this file, or any part of it, into the Codex task.**
It names the grading key. `TASK_PACKET.md` is the only agent-facing document.

---

## Experiment mode

This first historical pair cannot currently satisfy strict controlled-A/B
reproducibility because the Conventional arm's generated `composer.lock` bytes
were not preserved and its exact Codex model/config may no longer be provable.

Use one of these modes deliberately:

- `STRICT_CONTROLLED_AB` — only if the original Conventional `composer.lock`
  is recovered and hashes to `e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e`,
  and the exact Conventional model/config is recovered.
- `DIAGNOSTIC_PILOT` — use the prepared pinned lock and preserve grading,
  navigation logs, and substitution evidence, but make no byte-level causal
  claim between the two arms.

For the current available evidence, select:

    DIAGNOSTIC_PILOT

Do not silently upgrade it later.

## Preflight 1 — dependencies

The Conventional arm installed a lock hashing to:

    e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e

The prepared pin hashes differently. In `STRICT_CONTROLLED_AB`, that is a hard
stop. In `DIAGNOSTIC_PILOT`, install the prepared pin and record the mismatch:

```bash
cp experiments/nav-substitution-01/pinned/composer.lock.pinned composer.lock
sha256sum composer.lock
composer install
```

Do not describe this as the same dependency environment. The pin exists so the
MAP_FIRST run itself is reproducible and so every future pair starts from a
common dependency snapshot.

## Preflight 2 — history leakage (hard stop)

The accepted fix is commit `dbbe666ae92182eed8a6ebbfb453ac804399c75e`, a direct
child of the base and reachable from `origin/main`. One
`git log -- src/Index/AnalysisFingerprint.php` reaches the answer.

Give the arm the base tree **without descendants**. Export the tree, do not
hand over a clone:

```bash
git archive b8ecad69c6514514b40869e0a643b19fc019ebcf | tar -x -C <run-dir>
test ! -e <run-dir>/.git || { echo "STOP - history present"; exit 1; }
```

History stripping is required in both experiment modes. A breach invalidates
the run rather than merely downgrading it.

## Preflight 3 — model and config

If the Conventional task page still exposes its exact model, reasoning setting
and configuration, record them and use exactly those for MAP_FIRST.

If not, record `NOT_PROVABLE`. This is compatible only with
`DIAGNOSTIC_PILOT`; do not assume a match.

## Preflight 4 — task text

The Conventional task text is filed at `arms/conventional/task-text.txt` and
copied into `TASK_PACKET.md` §1. Treat the filed text as the source of truth.
If either file is edited, verify the section remains byte-identical before the
run. Do not paraphrase or reconstruct it from history.

## Preflight 5 — navigation evidence

The Conventional navigation log is now filed at
`arms/conventional/navigation-log.tsv` and reconciles exactly:

    source-read    7,781 bytes   4 calls
    text-search   17,944 bytes   2 calls
    file-listing      90 bytes   1 call
    -----------------------------------
    total         25,815 bytes   7 calls

The MAP_FIRST arm must use the same category boundaries.

## What the arm must not learn

The discriminating semantics — that the structural analyser's backend identity
and the value it emits into `phpStanVersion` are different — must not appear in
what is pasted. Finding that distinction through navigation is the most
interesting thing this pair measures. The grader knows; the arm must not.

The task itself legitimately contains the phrase `structural-only`, so the leak
check must not reject that task-required phrase. Check only discriminating
implementation details and the grading key:

```bash
grep -niE "phpStanVersion.{0,40}none|StructuralOnlySemanticAnalyzer|phpstan_package_reference|getReference|dbbe666" \
  experiments/nav-substitution-01/arms/map-first/TASK_PACKET.md \
  && echo "LEAK - do not paste" || echo "clean"
```

## After the run

1. `integrity/GATE.md` — classify integrity before looking at cost numbers.
2. `grader/grade.sh` — grade **both** candidates. The grader is sealed.
3. `analysis/COST_VIEWS.md` — report cold and steady Map views separately and
   classify every Map operation by substitution.
4. If this pair is `DIAGNOSTIC_PILOT`, the next pair must start both arms from
   `pinned/composer.lock.pinned` and a frozen model/config to become the first
   strict controlled A/B.
