# MAP-A pre-replay findings

These are facts discovered while freezing the cohort. They are not MAP-A outcome measurements.

1. `EditContextPlanner` already composes primary target, overrides/contracts, direct callers, direct and heuristic tests, callees, referenced types, diagnostics, blind spots and omission reasons before applying budgets. A new generic neighbour surface is therefore not justified by capability absence.
2. `EditContextPolicy` owner defaults are 20 files / 60,000 source bytes. The tighter 6-file / 16,000-byte projection discussed in issue #25 is a consumer/Loop policy and must not be misreported as an agent-map default.
3. The historical issue-101 dogfood workflow is contaminated for discovery comparison after raw Search: it explicitly supplies `ReflectionTypeFormattingRegressionTest.php` and `PhpCodeParser.php` to `workflow plan`. MAP-A must not grade that later governed context as independently discovered.
4. Historical issue-101 evidence is still useful as an oracle-independent structural hypothesis: the map contained a `calls` edge from `PHPClass::readObjectFromPhpNode` to the ranked `PHPProperty::readObjectFromPhpNode` seed. This is exactly what strategy C must test afresh.
5. Historical portable-ascii #62 evidence supplies the opposite shape: the eventual declaration-free PHP data file was Search-invisible. That replay is useful for testing whether Map silence can be interpreted safely and whether baseline reading wins.

Next action is execution of the nine A/B/C rows. Do not add product PHP before the table is populated.