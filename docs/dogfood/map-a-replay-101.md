# Replay 101 — staged protocol

Target: `voku/Simple-PHP-Code-Parser@53f1b5085ee883560afa9326ee914f6b23acd6ae`

Input: exact issue #101 title/body only.

The oracle remains the two later production files plus the focused regression anchor listed in `map-a-frozen-replays.md` and is opened only after each strategy's candidate/read set is frozen.

## A — baseline

Use repository-native text/file discovery only. Start from literal facts in the issue (PHP 8.5, `selfType`, the named regression test, and the reported `PhpCodeParser.php:529` location). Count every source/test file whose contents are materialised before the candidate set is frozen. Do not use Map output.

## B — ranked + bounded context

Build current agent-map over `src,tests`, build Search, query with the unchanged issue text, then consume ranked production method seeds through the same bounded shape Loop currently uses. Record raw Search bytes separately from exact source bytes selected by context planning. Do not pass known files through `workflow plan`.

## C — exact graph

From mechanically obtained B seeds only, use existing exact graph/context surfaces. The key falsification is whether the graph reaches a useful caller/owner location with less extra source than B's broader expansion. Do not seed `PHPClass` from the historical oracle.

## Grade after freeze

Only after A/B/C outputs are frozen, compare with the verified later fix and focused test. Then populate the three `simple-php-code-parser-101` rows in `map-a-results.tsv`.

If byte/candidate/command boundaries cannot be made identical enough for comparison, mark replay 101 invalid and repair the protocol before moving to replay 62.