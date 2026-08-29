# MAP_FIRST arm — task packet

Paste this into **one fresh** Codex Online task. Do not reuse the Conventional
task's thread. Do not run this arm more than once for this pair.

The **only** intended difference from the Conventional arm is the navigation
policy in §3. Everything else must be byte-identical.

---

## 0. Operator checklist (do this before starting the task)

- [ ] Fresh Codex Online task, same model and same configuration as the Conventional arm.
- [ ] Repository `voku/agent-map`, checked out at base
      `b8ecad69c6514514b40869e0a643b19fc019ebcf`.
- [ ] **Dependency pinning.** Copy `experiments/nav-substitution-01/pinned/composer.lock.pinned`
      to `composer.lock` in the checkout *before* `composer install`, then run
      `composer install`. `composer.lock` is `.gitignore`d in this repository
      and the requirements are floating carets, so without this step the two
      arms resolve independently and the pair fails the integrity gate on
      dependency drift. See `../../integrity/GATE.md`.
- [ ] **History leakage.** The accepted fix for this task is commit `dbbe666`,
      a descendant of the base and reachable from `origin/main`. If the task
      environment has full git history or a remote, the answer is one
      `git log` away. Either strip history (`git checkout --orphan` from the
      base tree, or export the base tree without `.git`) or require the arm to
      log every git command so history navigation is auditable.
      Record which option was used.
- [ ] Same validation command as the Conventional arm, run **once** (§4).

---

## 1. Task text

> **VERBATIM SLOT — do not paraphrase.**
> Paste the Conventional arm's task text here, byte-identical, from the frozen
> Conventional record. It is not reproduced in this session because it was not
> carried over; see `../conventional/result.json`. Reconstructing it from the
> historical commit message would break the "same task text" integrity
> criterion, so it is deliberately left empty rather than guessed.

---

## 2. Navigation logging (identical to the Conventional arm)

Log every navigation observation with: sequence number, tool, exact invocation,
and **output bytes returned to the model**. Categories:

- `map-build` — building or refreshing the agent-map index
- `map-nav` — any `agent-map` query/read command
- `source-read` — reading a repository file directly
- `text-search` — `rg` / `grep` / `find` / filename globbing
- `git-history` — any command that reads commits, diffs or branches

`map-build` is logged separately from `map-nav` on purpose: the cost comparison
reports cold and steady-state Map cost as two distinct numbers and never
averages them.

Report the totals at the end. An unlogged navigation call invalidates the arm.

---

## 3. Navigation policy — MAP_FIRST (the manipulated variable)

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
bin/agent-map scope   'voku\AgentMap\Index\AnalysisFingerprint::toArray' --index=.agent-map/php-symbols.json
bin/agent-map callers 'voku\AgentMap\Index\AnalysisFingerprint::__construct' --index=.agent-map/php-symbols.json
bin/agent-map context 'voku\AgentMap\Index\AnalysisFingerprint::__construct' --index=.agent-map/php-symbols.json
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

Note: at this base the repository contains **no** `AGENTS.md` and no navigation
guidance of any kind, so this section is the arm's only policy source. That is
intentional and keeps navigation policy the single manipulated variable.

---

## 4. Validation

Run the repository gate **exactly once**:

```bash
composer ci
```

Do not run it again, and do not run `composer ci` a second time under another
name. Record the output verbatim.

Repository validation passing is **not** evidence that the task was solved
correctly. Independent grading happens later and separately.

---

## 5. Deliverables

Commit nothing to a shared branch. Hand back:

1. the full navigation log with per-call byte counts and the category totals;
2. `sha256sum composer.lock`;
3. the candidate patch (`git diff` against the base) and its SHA-256;
4. the verbatim `composer ci` output;
5. blind spots: what you were unsure about, what you did not look at, and any
   place the map gave you an answer you chose not to trust;
6. the git-history handling used (stripped, or logged).

Codex Online owns its own branch and commit lifecycle for this run. A host-side
UI failure *after* the deliverables above are captured is a host finalization
failure, not an experiment failure — capture the evidence first.
