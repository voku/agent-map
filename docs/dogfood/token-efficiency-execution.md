# Token-efficiency self-diagnostic execution

The project-specific L1 prompt is [`token-efficiency-l1.md`](token-efficiency-l1.md). Its preflight
was executed on 2026-08-29 before either benchmark arm was started.

| Task | Arm | Input tokens | Navigation calls | Files read | Source lines | Correct | Validation |
| --- | --- | ---: | ---: | ---: | ---: | --- | --- |
| portable-ascii-135 | both blocked | NOT_OBSERVABLE | NOT_MEASURED | NOT_MEASURED | NOT_MEASURED | NOT_GRADED | contract ready |

## Classification: `INVALID_EXPERIMENT`

The controller revision and initial tracked-tree cleanliness matched the prompt. The
`portable-ascii-135` fixture now freezes executable setup, regression, full-suite and static-analysis
commands; acceptance assertions; required and allowed files/ranges; and the historical patch digest.
The preflight proves that contract is complete before an arm may start.

The remaining blocker is outside agent-map's owner boundary: this environment does not expose the
Runner-owned isolated coding-model attempts, independent conversation creation, or trustworthy
request-level input and tool telemetry required by the contract.

Running the two arms serially in this conversation would violate the no-contamination rule. Reusing
the deterministic navigation replay would answer a different question: that harness compares fixed
navigation policies without executing coding agents and cannot provide provider model-input usage.
The benchmark therefore stopped before any arm, source navigation, index build, or mutation. Cold and
steady-state costs are both `NOT_MEASURED`; no token, navigation, correctness, or task-shape advantage
is claimed.

The machine-readable preflight record is
`tools/dogfood/experiments/token-efficiency-execution.json`. It records the blocker independently for
each arm, the ready validation contract, the missing execution measurements, and the integrity
findings that force this classification.
