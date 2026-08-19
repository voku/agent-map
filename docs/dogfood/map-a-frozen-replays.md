# MAP-A frozen replay cohort

This document freezes the replay inputs for issue #25 before MAP-A measurements are collected. It is experiment provenance, not product behaviour.

## Rules

- Use only the frozen issue title/body as discovery input.
- Do not inspect the oracle/final fix before a strategy has produced its candidate set.
- Grade edit/test hits only after discovery output is frozen.
- Record files read, source bytes materialised, Map output bytes, candidate count, false candidates, and commands issued.
- Record the exact fact that could not be concluded from the observation channel.
- A Map miss is a valid result.

## Replay 1 — local/runtime behaviour with a data-file edit

Repository: `voku/portable-ascii`

Issue: `#62` — `[DE] MacOS (Big Sur or M1) uses multi character German umlaute`

Frozen base: `c5aede519cc55833267bcbb421b222d7aacfaa06`

Oracle, hidden until grading:

- production: `src/voku/helper/data/ascii_by_languages.php`
- regression: `tests/AsciiDecomposedUmlautTest.php`

Historical evidence is diagnostic only: the old raw Search found `ASCII.php`, `ASCII::to_ascii_replace()` and an `AsciiTest` method but could not retrieve the declaration-free data file. MAP-A must not treat that old result as the new replay.

## Replay 2 — caller/neighbour composition

Repository: `voku/Simple-PHP-Code-Parser`

Issue: `#101` — `PHP 8.5: reflection self formatting diverges and null array-key deprecations surface`

Frozen base: `53f1b5085ee883560afa9326ee914f6b23acd6ae`

Oracle, hidden until grading:

- production: `src/voku/SimplePhpParser/Parsers/PhpCodeParser.php`
- production: `src/voku/SimplePhpParser/Model/PHPClass.php`
- focused existing regression anchor: `tests/ReflectionTypeFormattingRegressionTest.php`

Historical evidence is diagnostic only: raw Search hit `PhpCodeParser.php` and `PHPProperty::readObjectFromPhpNode` but missed `PHPClass.php`; the frozen map contained the exact caller relation from `PHPClass::readObjectFromPhpNode` to `PHPProperty::readObjectFromPhpNode`.

Important contamination warning: the permanent historical workflow later passed known files explicitly to `workflow plan`. That governed context is therefore **not** valid MAP-A discovery evidence. Only pre-plan raw output or a fresh no-oracle replay may be graded.

## Replay 3 — dependency/config plus implementation location

Repository: `voku/Simple-PHP-Code-Parser`

Issue: `#60` — `Update for use with PHP 8.4`

Frozen base: `5156d5d74ca1bce275219f4571efd54ec44be911`

Oracle, hidden until grading:

- config: `composer.json`
- production: `src/voku/SimplePhpParser/Parsers/PhpCodeParser.php`

Historical evidence is diagnostic only: the old frozen raw Search returned `PhpCodeParser.php` at rank 3/10. The new MAP-A comparison must still run independently and measure reading/output cost rather than inheriting that conclusion.

## Strategies

For each replay:

- **A — baseline:** repository-native grep/search/read only, no Map output injected.
- **B — ranked:** frozen issue text -> ranked Search -> current bounded `EditContextPlanner` consumption shape.
- **C — exact/graph:** use only existing exact surfaces (`callers`, `callees`, `context`, `impact`) when the ranked/structural seed makes one mechanically applicable.

Do not invent a `neighbours` command or add an observation type for this experiment.

## Stop rule

After these three replays, write the evidence table and stop. MAP-B exists only if the measurements reproduce a concrete consumption or observation-truth shortfall.