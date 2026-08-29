## Goal

Run a controlled, falsifiable case study at agent-map revision
`dc649961923bfe5fcf42a6833de6c49077caf2f0` to test whether Map-first navigation reduces
model-visible input tokens, navigation calls, source exposure, duplicate reads, and work before the
first correct edit without reducing correctness. Compare navigation strategies, not tasks: each
task/arm pair receives the same frozen checkout, task text, acceptance criteria, model configuration,
permissions, limits, and validation. A cheaper failed or incomplete edit is not a win.

Use these already-solved, pre-registered replays rather than inventing Map-friendly work:

1. `portable-ascii-135` (`88f94f89fe03bed03eb8fbcfb84178a8a5eb1d5b`): known/local
   `voku\helper\ASCII::to_ascii` repeated-call regression; the existing fixture supplies task text,
   independently verified edit range, and existing regression test.
2. `simple-php-code-parser-101` (`53f1b5085ee883560afa9326ee914f6b23acd6ae`): cross-file
   PHP 8.5 compatibility fix; the fixture supplies two independently verified production ranges and
   the pre-existing regression test. Treat the two observations independently unless evidence joins
   them.
3. `portable-ascii-62` (`c5aede519cc55833267bcbb421b222d7aacfaa06`): negative control for
   symbol-less PHP data/config; the independently verified location is a language-data array and the
   historical fix added a test.

The hypothesis is not presumed true. The permitted final classifications are `CLEAR_WIN`,
`MODEST_WIN`, `NEUTRAL`, `MIXED`, `LOSS`, and `INVALID_EXPERIMENT`.

## Context

- Controller repository: `/workspace/agent-map` at exactly
  `dc649961923bfe5fcf42a6833de6c49077caf2f0`; abort as invalid if `git rev-parse HEAD` differs or
  tracked files are dirty before orchestration.
- Task definitions and pre-registered ground truth are
  `tools/dogfood/replays/{portable-ascii-135,simple-php-code-parser-101,portable-ascii-62}.json`.
  Copy only each fixture's `task` object into the arm prompt. Keep `verified` hidden from the coding
  agent and use it only for independent post-run grading.
- Before running either arm, a reviewer must freeze, in the experiment manifest, the exact historical
  patch (or reviewed expected diff), required files, acceptance assertions, and executable validation
  commands for every task. A fixture whose `validation` is null may not start until this is done.
- Available Map surfaces are `query`, `file`, `related`, `scope`, `context`, `callers`, `callees`,
  `impact`, and the typed rename/removal plans exposed by `vendor/bin/agent-map`. Only the narrowest
  relevant surface may be used. No typed change plan is applicable to these three tasks, so plan
  metrics are `NOT_APPLICABLE`, not zero.
- Use structural and PHPStan backend trials separately where feasible. A requested backend must match
  the map's recorded `analysis_fingerprint`; structural operation is an explicit trial, never a
  fallback after PHPStan fails.
- Create one detached clean worktree per task/arm/trial from the frozen upstream commit. Run each arm
  in a new coding-model conversation with no shared transcript, cache, snippets, path hints, patches,
  summaries, or controller memory. Alternate arm order between repeated trials. Use at least three
  independent trials when budget permits; otherwise label the output a non-statistical case study.
- Use the same coding model, immutable model identifier, sampling/reasoning settings, system and
  repository instructions, context limit, timeout, tools, network policy, and stopping rules. Record
  nondeterminism when a seed or deterministic mode is unavailable.
- Instrument the host at its model-request and tool-dispatch boundaries. Each request record must
  contain provider input-token usage (when available), request category, conversation/run ID, and
  compaction/resume marker. Each tool event must contain command/surface, purpose category, complete
  byte count, and source ranges returned to the model. Do not depend on self-reported agent counts.
- Produce both cost views: `cold_start_cost` includes clone/install/index construction and all
  model-visible build output; `steady_state_cost` begins from an independently verified fresh map and
  warm dependency cache. Never merge the views or charge index construction to only one arm. Host-side
  build time/bytes not shown to the model are reported separately from input tokens.

## Constraints

- **Arm A — conventional:** allow normal `rg`, path search, bounded shell inspection, source reads,
  editing, static analysis, and tests. Do not cripple it or prescribe pointless calls. Forbid
  agent-map commands, APIs, artifacts, generated projections, and plans.
- **Arm B — Map-first:** for represented PHP facts, require the narrowest progression: exact known
  target to `scope`; method edit needing bounded surroundings to `context`; unresolved exact relation
  to `callers`/`callees`; unknown target to `query`/`related`/`file`. Use `discover` only for genuine
  orientation. For the data/config control, literals, templates, prose, generated artifacts, an
  explicit Map blind spot, or post-selection source verification, permit `rg` and bounded reads.
  Record why every fallback was allowed. Do not force Map ceremony where text search is cheaper.
- The coding agent owns intent and mutation. Map supplies read-only evidence/plans and must not choose
  work, recommend architecture, apply a change, approve it, or execute validation. The host must
  validate provenance, hashes, backend, blockers, review state, and staleness before using evidence.
- Do not reveal ground truth, historical patches, other-arm output, inferred symbols/paths, or grading
  data to an arm. Do not reuse a conversation or allow the second run to inherit compaction state.
- Stop each arm only after its shared acceptance contract passes or a genuine blocker is recorded.
  Do not explore after sufficiency, terminate one arm based on the other's progress, weaken tests, or
  count a cheap incorrect result as efficient.
- Persist the smallest auditable evidence: manifest, prompts and their hashes, normalized request
  usage, normalized tool events, final diff hash/diffstat, validation exit codes/output digests,
  grading, per-run JSON, and comparison report. Avoid full transcripts unless needed to audit a
  disputed count; redact secrets. Use one JSON record per task/arm/trial with `task_id`, `arm`,
  `repository_sha`, `result`, `correctness`, `tokens`, `navigation`, `source_exposure`, `map_usage`,
  `validation`, and `blind_spots`.
- Pre-register exclusions. Do not discard inconvenient trials. If authoritative token accounting is
  unavailable, use one deterministic tokenizer over every input message in both arms and label all
  token values `ESTIMATED`; otherwise use only provider counts. Never mix authoritative and estimated
  values. Unobservable categories are the string `NOT_OBSERVABLE`.

## Verification

1. Preflight the controller with:

   ```bash
   test "$(git -C /workspace/agent-map rev-parse HEAD)" = dc649961923bfe5fcf42a6833de6c49077caf2f0
   test -z "$(git -C /workspace/agent-map status --porcelain --untracked-files=no)"
   composer --working-dir=/workspace/agent-map ci
   jq -e '.validation != null' /workspace/agent-map/tools/dogfood/replays/*.json
   ```

   The final command deliberately blocks execution until shared validation contracts have been
   pre-registered. Record any failure; do not improvise acceptance criteria after seeing an arm.
2. Freeze the model runner command, immutable model/config, prompt SHA-256, tool schema SHA-256, host
   version, PHP/Composer versions, environment digest, upstream SHA, worktree path, trial number, arm
   order, backend, cache state, and timestamps in `manifest.json`. Prove worktree equality with
   `git status --porcelain` and `git rev-parse HEAD` immediately before every run.
3. Export provider request usage as JSONL. Sum exactly the provider's input fields with a checked-in
   normalization script or, if absent, mark them `NOT_OBSERVABLE`. Partition without overlap into
   `initial_prompt_tokens`, `navigation_output_tokens`, `source_read_tokens`,
   `validation_output_tokens`, and `resume_or_compaction_tokens`; assert their sum equals
   `total_input_tokens`. Record system/repository prompt tokens for both arms even when equal.
4. Export every tool dispatch as JSONL and classify its pre-mutation purpose as
   `map_query_calls`, `map_scope_calls`, `map_context_calls`, `map_relation_calls`, `map_plan_calls`,
   `text_search_calls`, `file_listing_calls`, `source_read_calls`, or `other_navigation_calls`.
   Preserve raw command hashes and outputs' byte counts so an independent reviewer can recalculate.
5. Parse delivered source spans as `(repository_sha,path,start,end,content_hash)`. Calculate
   `unique_source_files_read`, `total_source_ranges_read`, unioned `unique_source_lines_read`,
   `total_source_lines_delivered`, repeated intersection as `duplicate_source_lines_delivered`, and
   `source_bytes_delivered`. Map headers without source count as Map output, not source lines. Record
   `tool_calls_before_first_correct_edit`, `tokens_before_first_correct_edit`, and
   `source_lines_before_first_correct_edit`; identify the first correct edit only during blinded
   post-run grading.
6. Grade against the pre-frozen expected patch/tests, never the better arm. Record
   `task_completed`, `acceptance_criteria_passed`, `tests_passed`, `static_analysis_passed`,
   `required_files_changed`, `unexpected_files_changed`, `incorrect_or_missing_change`,
   `regressions`, and `manual_review_findings`. Run the exact same task-specific commands in both arms,
   followed by the upstream repository's frozen Composer CI command. Also run
   `composer --working-dir=/workspace/agent-map ci` once to validate the controller/harness.
7. Record independently grounded `required_files_in_ground_truth`,
   `required_files_found_before_edit`, `irrelevant_files_read`, `required_symbols_found`,
   `wrong_candidate_symbols_inspected`, and `navigation_backtracks`. Calculate `file_precision` as
   required files read divided by all files read, and `file_recall` as required files read divided by
   required files in ground truth; define zero-denominator handling in the manifest.
8. Classify each Map invocation `USEFUL`, `REDUNDANT`, `INCOMPLETE`, `MISLEADING`, or
   `NOT_APPLICABLE`. Record surface, output tokens/bytes, displaced search/read, repeated manual read,
   blind spots, stale evidence, unresolved relations, unsupported shapes, and cheaper text fallbacks.
   Plan fields (`plan_generation_tokens`, `plan_output_tokens`, `manual_discovery_calls_avoided`,
   `manual_edit_reasoning_avoided`, `planned_edits`, `applied_edits`, `plan_blocked`,
   `plan_review_required`, `plan_blind_spots`, `post_apply_validation_result`) are
   `NOT_APPLICABLE` here.
9. Check integrity: distinct run/conversation IDs; clean and equal starting SHAs; matching prompts,
   settings, tools, limits, validation, and environment; no cross-arm paths/content in prompts; no
   hidden host context; backend identity matches request; map freshness holds; all token partitions
   reconcile; all tool events have classifications; and cold/steady rows remain separate. Any material
   breach forces `INVALID_EXPERIMENT`.
10. For every task/trial and for medians when repeated, report raw rows and calculate:

    ```text
    token_savings_absolute = conventional_total_input_tokens - map_total_input_tokens
    token_savings_percent = token_savings_absolute / conventional_total_input_tokens * 100
    navigation_call_reduction = conventional_navigation_calls - map_navigation_calls
    source_line_reduction = conventional_total_source_lines - map_total_source_lines
    duplicate_read_reduction = conventional_duplicate_lines - map_duplicate_lines
    correctness_delta = map_correctness - conventional_correctness
    ```

    Handle a zero/unknown denominator as `NOT_COMPUTABLE`. Include a compact table with columns
    `Task | Arm | Input tokens | Navigation calls | Files read | Source lines | Correct | Validation`,
    per-task deltas, aggregate medians, min/max spread, cold/steady views, and time-to-first-correct-edit
    measures. Do not collapse the evidence to one percentage or claim statistical significance from
    this case study.
11. Explain threats from model nondeterminism, warmed caches, task-selection bias, prior repository
    familiarity, prompt-size differences, index construction/amortization, additive Map-plus-identical
    reads, hidden context, compaction/resume, repair tokens, JSON versus TOON formatting, cached/generated
    evidence, and out-of-domain tasks.

## Done When

- Every arm/task/trial either meets the identical acceptance contract or has an explicit blocker.
- Token accounting is present with a single declared method or each unavailable category is
  `NOT_OBSERVABLE`; navigation and source metrics are captured and raw evidence supports every delta.
- Correctness is independently graded with the same validation; Map uses are classified; plan
  non-applicability is explicit; cold-start and steady-state costs are not conflated.
- Integrity checks and all required blind spots are recorded. Contamination or unequal conditions
  produce `INVALID_EXPERIMENT`, not a repaired narrative.
- The final report gives exactly one conservative classification from the allowed set, explains it
  from raw evidence, and states which tested task shapes Map demonstrably helps, does not help, or
  cannot represent. No product changes, autonomous backlog items, or architecture recommendations are
  created from the result.
