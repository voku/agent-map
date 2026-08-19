# MAP-A execution boundary

The cohort is frozen. Further task selection or API exploration is out of scope until the nine strategy rows are measured.

## Execution order

1. `Simple-PHP-Code-Parser#101` first because it contains a known Search-vs-graph contrast and therefore validates the measurement method quickly.
2. `portable-ascii#62` second because it contains a known declaration-free data-file blind spot and tests safe interpretation of Map silence.
3. `Simple-PHP-Code-Parser#60` last as the config/dependency plus implementation case.

For each replay, finish A/B/C before opening the oracle. If a strategy cannot be applied mechanically, record `not_applicable` rather than manufacturing a seed from hindsight.

## Measurement validity check after replay 1

Before continuing to replay 2, require all of the following:

- source-byte counting has one definition across A/B/C;
- Map output bytes count only bytes exposed to the agent/consumer, not index/database size;
- candidate count has one definition across B/C;
- commands are counted from the same boundary;
- the oracle was not read until candidates were frozen;
- the issue text did not leak known edit files through a pre-written plan.

If any item fails, repair the experiment and repeat replay 1. Do not proceed with incomparable numbers.

## No-product-change gate

Until all three replays are complete, this branch may contain experiment provenance/results only. No changes under `src/`, `bin/`, or product tests are justified by MAP-A.