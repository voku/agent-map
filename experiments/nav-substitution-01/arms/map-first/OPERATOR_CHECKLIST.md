# MAP_FIRST — operator checklist

**Never paste this file, or any part of it, into the Codex task.**
It names the grading key. `TASK_PACKET.md` is the only agent-facing document,
and it is written so the whole file can be pasted safely.

---

## Preflight 1 — dependency lock (hard stop)

The Conventional arm installed a lock hashing to:

    e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e

```bash
cp experiments/nav-substitution-01/pinned/composer.lock.pinned composer.lock
test "$(sha256sum composer.lock | cut -d' ' -f1)" \
  = "e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e" \
  || { echo "STOP - DO NOT RUN MAP_FIRST"; exit 1; }
composer install
```

**This check currently fails.** `pinned/composer.lock.pinned` was resolved
independently and hashes to `2504f36b…`, not `e465a590…`. A SHA-256 cannot be
inverted into a lock file, so the Conventional arm's *actual* `composer.lock`
has to be filed at `arms/conventional/composer.lock` and copied over the pin
before this preflight can pass. See `../../pinned/README.md`.

Do not run MAP_FIRST until this preflight passes. That is the whole point of
moving dependency drift from a post-hoc invalidation to a preflight.

## Preflight 2 — history leakage (hard stop)

The accepted fix is commit `dbbe666ae92182eed8a6ebbfb453ac804399c75e`, a direct
child of the base and reachable from `origin/main`. One
`git log -- src/Index/AnalysisFingerprint.php` reaches the answer.

Give the arm the base tree **without descendants**. Export the tree, do not
hand over a clone:

```bash
git archive b8ecad69c6514514b40869e0a643b19fc019ebcf | tar -x -C <run-dir>
# verify nothing reachable came along
test ! -e <run-dir>/.git || { echo "STOP - history present"; exit 1; }
```

Asking the arm not to peek and auditing afterwards is the weaker option: it
leaves the temptation in place and puts the burden on the log. Removing the
descendants removes both.

## Preflight 3 — model and config

Freeze the Conventional task's model, reasoning setting and configuration into
`arms/conventional/result.json`, then use exactly those for MAP_FIRST.

If the Codex task page no longer exposes them, record criterion 5 as
`NOT_PROVABLE` and classify the pair as **diagnostic pilot**, not strict
controlled A/B. Do not assume they matched.

## Preflight 4 — task text

`TASK_PACKET.md` §1 is an empty verbatim slot. Fill it from
`arms/conventional/task-text.txt`, byte-identical. Do not paraphrase and do not
reconstruct it from the commit message.

## What the arm must not learn

The discriminating semantics — that the structural analyser reports backend
identity `structural-only` while emitting `phpStanVersion` `'none'` — must not
appear anywhere in what is pasted. Finding that distinction through navigation
is the most interesting thing this pair measures. The grader knows; the arm
must not.

Before pasting, confirm:

```bash
grep -niE "structural-only|sentinel|phpstan_reference|getReference|StructuralOnly|dbbe666" \
  experiments/nav-substitution-01/arms/map-first/TASK_PACKET.md \
  && echo "LEAK - do not paste" || echo "clean"
```

## After the run

1. `integrity/GATE.md` — run the gate before looking at any cost number.
2. `grader/grade.sh` — grade **both** arms. The grader is sealed; do not edit
   it now that MAP_FIRST has been observed.
3. `analysis/COST_VIEWS.md` — cost views and substitution classification.
