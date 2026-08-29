# MAP_FIRST arm — task packet

Paste this into **one fresh** Codex Online task. Do not reuse the Conventional
task's thread.

The **only** intended semantic difference from the Conventional arm is the
navigation policy in §4. Everything else stays frozen for this pilot.

---

> This whole file is safe to paste into the Codex task.
> Operator-only preparation lives in `OPERATOR_CHECKLIST.md`, which must never
> be pasted.

---

## 0. Host execution rules — mandatory

The execution host may detach long-running shell commands and return a session
or process handle before the command exits. **Detachment is not failure.**

For every long-running command, especially `agent-map build`, dependency
installation, and `composer ci`:

1. launch it exactly once;
2. if the host returns a live session/process handle, poll that **same** handle
   until the process exits;
3. capture the final exit status/output from that original execution;
4. do not launch an equivalent replacement command merely because the UI or
   tool call detached;
5. never manually synthesize a navigation-log row for a command the wrapper did
   not finish recording.

If a process is genuinely terminated by the host and no authoritative wrapper
row was produced, record the host problem and the missing measurement. Do not
repair the TSV by hand.

Before the candidate freeze, every repository-content read/list/search must be
logged. **Reporting is not an exception.** In particular, do not use an
unwrapped `find`, `ls`, glob, or `rg --files` to discover changed files at the
end. Derive the changed-file list from the already-frozen candidate patch.

---

## 1. Task text

"AnalysisFingerprint" records the PHPStan version used to produce semantic analysis, but the version alone does not establish the exact Composer package revision that supplied the analyser.

Extend the fingerprint provenance contract so that:

1. a newly created PHPStan-backed fingerprint records the exact installed "phpstan/phpstan" package reference;
2. a structural-only fingerprint records that there is no PHPStan package reference;
3. historical serialized fingerprints that predate this field remain readable and expose the missing reference explicitly rather than inventing current-runtime provenance;
4. an already serialized exact package reference round-trips unchanged and must not be replaced with information from the current runtime;
5. serialization exposes the new provenance;
6. existing fingerprint behavior remains compatible.

Add focused regression coverage.

Do not change backend selection, ranking, dependency ownership, or unrelated map semantics.

---

## 2. Navigation logging (identical category boundaries to Conventional)

Log every navigation observation with: sequence number, tool, exact invocation,
and **output bytes returned to the model**. Categories:

- `map-build` — building or refreshing the agent-map index
- `map-nav` — any `agent-map` query/read command
- `source-read` — reading a repository file directly
- `text-search` — `rg` / `grep` searching file *contents*
- `file-listing` — `rg --files`, `find`, `ls`, filename globbing
- `git-history` — any command that reads commits, diffs or branches

`text-search` and `file-listing` are separate categories because the
Conventional arm used both and they substitute differently: a content search
competes with `query`/`related`, a filename search competes with knowing where
a symbol lives. Its 7 calls were 4 source-read, 2 text-search, 1 file-listing.

`map-build` is logged separately from `map-nav` on purpose: the cost comparison
reports cold and steady-state Map cost as two distinct numbers and never
averages them.

The run directory has no git history, so `git-history` should stay empty. It
exists so that an attempt is visible rather than silent.

Only wrapper-produced rows are authoritative. Never reconstruct a missing row
from memory, terminal fragments, or inferred output.

Report the totals at the end. An unlogged repository-navigation call invalidates
the arm.

---

## 3. Candidate-freeze reporting rule

Generate the candidate patch first. After that, derive changed files **from the
patch itself**, not by rereading/listing the repository.

For a unified patch containing `diff -ruN` output, changed paths can be derived
from its `diff`/`---`/`+++` headers. Any equivalent parsing of the already-created
patch artifact is acceptable because it does not perform new repository
navigation.

Do not run `find src tests`, `rg --files`, `ls`, or filename globs merely to
produce the final changed-files list.

---

## 4. Navigation policy — MAP_FIRST (the manipulated variable)

Build the map first, then navigate through it. Use text search only where the
map genuinely cannot answer the question, and say so in the log when you do.

The intended path is:

```
build self-map
  -> resolve the target identity through the map
  -> take the bounded relevant context the map gives you
  -> verify in source only what you still actually need
  -> edit
```

At this base the CLI is `bin/agent-map` with these commands:

```
build refresh search-index search query file stale summary changed related stats
scope callers callees context
```

Useful invocations at this base (from `bin/agent-map help`):

```bash
bin/agent-map build --root=. --paths=src,tests --out=.agent-map/php-symbols.json
bin/agent-map query   AnalysisFingerprint            --index=.agent-map/php-symbols.json
bin/agent-map file    src/Index/AnalysisFingerprint.php --index=.agent-map/php-symbols.json
bin/agent-map related AnalysisFingerprint            --index=.agent-map/php-symbols.json
bin/agent-map scope   'voku\\AgentMap\\Index\\AnalysisFingerprint::toArray' --index=.agent-map/php-symbols.json
bin/agent-map callers 'voku\\AgentMap\\Index\\AnalysisFingerprint::__construct' --index=.agent-map/php-symbols.json
bin/agent-map context 'voku\\AgentMap\\Index\\AnalysisFingerprint::__construct' --index=.agent-map/php-symbols.json
```

Options include `--format=text|json|markdown|toon`, `--limit`, `--context-budget`,
`--max-files`, `--max-callers`, `--max-callees`, `--max-tests`.

The invocations are listed so that CLI trial-and-error is not charged to Map as
a navigation cost — the Conventional arm did not have to discover how `rg`
works either. Which of them you use, in what order, and whether you use them at
all is yours to decide and is exactly what is being measured.

**Do not** consult the map and then re-derive the same fact with `rg` or a full
file read "to be sure". If you do it anyway, log it — that outcome is
classified as `REDUNDANT` and is a real finding, not a mistake to hide.

If `map-build` is long-running and the host detaches it, follow §0: wait/poll the
same execution to completion. Do **not** start a second build while the first may
still be running. A second build is allowed only after authoritative evidence
that the first process exited and only when a genuine candidate-independent
retry reason exists.

Note: at this base the repository contains **no** `AGENTS.md` and no navigation
guidance of any kind, so this section is the arm's only policy source. That is
intentional and keeps navigation policy the single manipulated variable.

---

## 5. Validation

Run the registered arm gate **exactly once**:

```bash
composer ci
```

If the host detaches this command, poll the same execution to completion and
capture that execution's final status/output. Do not start another `composer ci`
merely to obtain an exit code.

Run candidate-specific follow-up validation only if the original gate actually
reported a candidate failure that required a fix; record every such rerun.

Repository validation passing is **not** evidence that the task was solved
correctly. Independent grading happens later and separately. `composer ci` is
also not assumed to represent every historical repository CI consumer proof.

---

## 6. Deliverables

Commit nothing to a shared branch. Hand back:

1. the full navigation log with per-call byte counts and category totals;
2. `sha256sum composer.lock`;
3. the candidate patch against the base and its SHA-256;
4. the `composer ci` result from the original execution, including final exit
   status when the host exposes it;
5. the changed-file list parsed from the frozen candidate patch;
6. blind spots: what you were unsure about, what you did not look at, and any
   place the map gave you an answer you chose not to trust;
7. the git-history handling used (stripped, or logged);
8. any detached host sessions and how the original execution was awaited.

A host-side UI failure *after* the deliverables above are captured is a host
finalization failure, not an experiment failure — capture the evidence first.
