# Token-efficiency self-diagnostic execution

The project-specific L1 prompt is [`token-efficiency-l1.md`](token-efficiency-l1.md). Its preflight
was executed on 2026-08-29 before either benchmark arm was started.

| Task | Arm | Input tokens | Navigation calls | Files read | Source lines | Correct | Validation |
| --- | --- | ---: | ---: | ---: | ---: | --- | --- |
| portable-ascii-135 | both blocked | NOT_OBSERVABLE | NOT_MEASURED | NOT_MEASURED | NOT_MEASURED | NOT_GRADED | missing contract |
| simple-php-code-parser-101 | both blocked | NOT_OBSERVABLE | NOT_MEASURED | NOT_MEASURED | NOT_MEASURED | NOT_GRADED | missing contract |
| portable-ascii-62 | both blocked | NOT_OBSERVABLE | NOT_MEASURED | NOT_MEASURED | NOT_MEASURED | NOT_GRADED | missing contract |

## Classification: `INVALID_EXPERIMENT`

The controller revision and initial tracked-tree cleanliness matched the prompt. The environment did
not expose an isolated coding-model runner, independent conversation creation, or request-level token
telemetry. More importantly, all three existing replay fixtures deliberately have `validation: null`.
The prompt pre-registers those contracts as a condition of starting an arm so that validation cannot
be weakened or invented after observing results.

Running the two arms serially in this conversation would violate the no-contamination rule. Reusing
the deterministic navigation replay would answer a different question: that harness compares fixed
navigation policies without executing coding agents and cannot provide provider model-input usage.
The benchmark therefore stopped before any arm, source navigation, index build, or mutation. Cold and
steady-state costs are both `NOT_MEASURED`; no token, navigation, correctness, or task-shape advantage
is claimed.

The machine-readable preflight record is
`tools/dogfood/experiments/token-efficiency-execution.json`. It records the blocker independently for
each task and arm, the missing measurements, and the integrity findings that force this classification.
