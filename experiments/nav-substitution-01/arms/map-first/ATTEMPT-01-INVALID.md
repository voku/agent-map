# MAP_FIRST attempt 01 — INVALID_ARM

This attempt reached Phase A, produced and froze a candidate, and executed the
sealed grader, but it is **not a valid arm** under the pre-registered integrity
rules.

## Verdict

`INVALID_ARM`

The decisive integrity violation was an unwrapped repository-content listing
immediately before candidate freeze:

```text
find src tests -type f -newer ...
```

A second instrumentation defect affected the map-build telemetry: one terminated
map-build attempt was reconstructed manually in the TSV instead of being emitted
atomically by the navigation wrapper. Neither defect is repaired or normalized
post hoc.

## Substantive outcome, not accepted as an experiment result

The frozen candidate graded `0` (sealed grader: 5 tests, 18 assertions, 4
failures). Conventional is also `GRADE=0`, so absent the integrity violation the
pair would have landed in the shared-failure cell.

This is recorded only as diagnostic context. It is **not** promoted to the
pair-level verdict because the arm is invalid.

The attempted candidate changed:

- `src/Build/SemanticAnalysisResult.php`
- `src/Build/PhpStanSemanticAnalyzer.php`
- `src/Index/AgentMapBuilder.php`
- `src/Index/AnalysisFingerprint.php`
- `tests/AgentMapBuilderTest.php`
- `tests/AgentMapIndexTest.php`

Candidate patch SHA-256:

```text
32c63dfedb950bff0edd3436524a7ba54c0de5f67e3b7eac05e7e37145718ad8
```

Pinned dependency SHA-256:

```text
2504f36bb16a168111aa309107c0460fc9c22b86618fca6527c3d28f66c639f6
```

Logged navigation before invalidation:

```text
navigation calls        29
navigation bytes        55,240
source-read calls       19
source-read bytes       49,715
text-search calls        3
text-search bytes        4,099
file-listing calls       1 logged (+1 unlogged, decisive violation)
file-listing bytes      50 logged
map-build bytes          1,206 (contains non-authoritative reconstructed row)
map-navigation bytes       170
```

The self-map never produced `.agent-map/php-symbols.json`; both
`query AnalysisFingerprint` attempts therefore failed and implementation
evidence came from fallback search/source reads.

## Host/harness findings to fix before retry

1. A command returning a detached execution/session handle is not evidence of
   failure. Poll the same handle until authoritative process completion instead
   of launching another equivalent command.
2. Never manually synthesize or repair navigation TSV rows. Only rows written by
   the wrapper itself are authoritative.
3. Derive the changed-file list from the frozen candidate patch; do not inspect
   the repository again with `find`, `rg --files`, `ls`, or globbing merely to
   prepare the report.
4. `composer ci` must likewise be allowed to finish through the original host
   execution/session so its exit status is captured atomically. Do not rerun it
   merely because the UI detached.

No grader change follows from this attempt. The grader remains frozen at
SEAL-3. No agent-map product conclusion is drawn from this invalid arm.
