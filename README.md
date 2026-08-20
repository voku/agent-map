# agent-map

Deterministic PHP repository maps for coding-agent context selection.

`agent-map` always records structural repository facts and enriches them when the PHPStan capability is installed:

- `voku/simple-php-code-parser` records physical declarations and source ranges;
- optional PHPStan 2.2 resolves PHPDoc types, generics, call targets, inheritance, and semantic relationships.

The results are reconciled into one map that can answer focused questions such as:

```bash
vendor/bin/agent-map discover
vendor/bin/agent-map callers 'App\Service\UserService::save'
vendor/bin/agent-map callees 'App\Service\UserService::save'
vendor/bin/agent-map context 'App\Service\UserService::save' --format=toon
```

The important output is not a grand graph for admiring in meetings. It is a bounded, source-backed edit context that `agent-loop` and `agent-recall-compiler` can use without asking an LLM to rediscover the repository first.

## Boundaries

`agent-map` owns:

```text
repository analysis
→ reconciled symbols, types, and relations
→ deterministic queries
→ EditContextPlan
```

It does not:

- call an LLM;
- write the final implementation prompt;
- modify source code;
- execute tests;
- store durable project learning.

Those responsibilities belong to the surrounding `agent-*` packages.

## Requirements

- PHP 8.2 or newer
- Composer
- PHPStan 2.2 only when PHPStan-backed semantic enrichment is required

## Installation

```bash
composer require --dev voku/agent-map
```

Install PHPStan explicitly when semantic enrichment is wanted:

```bash
composer require --dev phpstan/phpstan:^2.2
```

Without PHPStan, map builds remain available with backend identity `simple-php-code-parser+structural-only`. When PHPStan is installed, the default backend remains `simple-php-code-parser+phpstan`. A selected PHPStan backend never falls back after an execution or configuration failure.

## Build a map

JSON remains the default interoperable storage format:

```bash
vendor/bin/agent-map build \
  --root=. \
  --paths=src,tests \
  --out=.agent-map/php-symbols.json
```

TOON is an optional compact serialization of the same model:

```bash
vendor/bin/agent-map build \
  --root=. \
  --paths=src,tests \
  --out=.agent-map/php-symbols.toon \
  --format=toon
```

There is one analysis path and one map model. JSON and TOON are serializers, not competing architectures.

### Build options

- `--root`: repository root, default current directory;
- `--paths`: comma-separated PHP files or directories, default `.`;
- `--out`: map file, default `.agent-map/php-symbols.json`;
- `--format`: `json` or `toon`, default `json`;
- `--phpstan-config`: explicit PHPStan configuration when the PHPStan backend is available;
- `--phpstan-memory-limit`: explicit positive PHPStan memory limit, for example `512M` or `2G`;
- `--scan`: comma-separated directories that only have to resolve symbols and are never indexed;
- `--merge`: patch the existing `--out` map instead of replacing it;
- `--exclude`: repeatable PHP regular expression applied to normalized paths.

Keep `--paths` on directories when you can. PHPStan turns its result cache off as soon as it is
handed individual files, so a file-list scope re-analyses everything on every build, while a
directory scope makes an unchanged rebuild close to free. `--exclude` falls back to the file list.

Use `--scan` when the analysed scope references classes that live outside it. Without it PHPStan
cannot resolve those types and reports `Class X was not found ... discovering symbols is probably
not configured properly`, which silently costs call edges:

```bash
vendor/bin/agent-map build --paths=src --scan=lib,vendor/acme
```

Configuration discovery uses:

1. `--phpstan-config`;
2. `phpstan.neon`;
3. `phpstan.neon.dist`;
4. a generated level-0 configuration.

Project PHPStan findings are stored as diagnostics when the semantic export itself succeeds. Parse failures, internal PHPStan failures, or a missing semantic export fail the build.

## What the map contains

### Files

- repository-relative path;
- SHA-256 source hash;
- namespace;
- structural and semantic status.

### Symbols

- classes, interfaces, traits, enums, functions, and methods;
- exact declaration ranges;
- inheritance, interfaces, traits, and attributes;
- native, PHPDoc, and PHPStan-resolved parameter and return types;
- PHPStan template types and resolved generic ancestors;
- reconciliation state.

For example:

```text
native return:   Entity|null
PHPDoc return:   T|null
resolved return: User|null
```

Generics are regular PHPStan types. There is no separate ceremonial generic subsystem.

### Relations

- `defines`
- `declares_method`
- `extends`
- `implements`
- `uses_trait`
- `overrides`
- `calls`
- `instantiates`
- `references_type`

Relations record source locations and one of these resolution states:

- `structural_only`
- `phpstan_resolved`
- `multiple_targets`
- `dynamic`

Dynamic facts stay visible, but they are never promoted into imaginary certainty.

### Reconciliation

Comparable parser and PHPStan facts are classified as:

- `confirmed`
- `semantic_enrichment`
- `structural_only`
- `phpstan_only`
- `conflict`

Conflicted symbols cannot be used as edit targets.

## Commands

All read commands accept either a JSON or TOON index. The input format is detected from the file extension, while `--format` controls command output.

### Locate symbols

```bash
vendor/bin/agent-map query UserService
vendor/bin/agent-map file src/Service/UserService.php
vendor/bin/agent-map related UserService
```

### Inspect dependencies

```bash
vendor/bin/agent-map callers 'App\Service\UserService::save'
vendor/bin/agent-map callees 'App\Service\UserService::save'
```

Method edit targets are exact:

```text
Foo::bar
App\Foo::bar
\App\Foo::bar
```

A short class name that matches multiple methods fails and lists the fully qualified candidates. Editing the wrong `Foo` faster was not a requested feature.

### Discover architecture

```bash
vendor/bin/agent-map discover
vendor/bin/agent-map rank --by=dependents --top=20
vendor/bin/agent-map impact 'App\Service\UserService::save' --depth=3
```

`discover` derives evidence-backed repository orientation without requiring a search query. It reports entrypoint candidates, call hubs, orchestrators, type hubs, relation quality, and coupling across namespaces, directories, and files.

Namespaces are deliberately not the only architecture signal. PHP allows projects without namespaces, so path and file coupling remain available for flat and legacy codebases.

`rank` counts unique one-hop graph neighbours. `impact` performs a bounded, cycle-safe reverse traversal and preserves relation evidence, path nodes, truncation, and `dynamic` / `multiple_targets` uncertainty instead of collapsing them into an opaque score.

See [Architecture discovery](docs/architecture-discovery.md) for the complete command, semantics, legacy-PHP, freshness, and library-API documentation.

### Generate edit context

```bash
vendor/bin/agent-map context 'App\Service\UserService::save' \
  --index=.agent-map/php-symbols.json \
  --context-budget=60000 \
  --max-files=20 \
  --max-callers=10 \
  --max-callees=10 \
  --max-tests=10 \
  --format=toon
```

The resulting `EditContextPlan` contains:

- the primary method;
- implemented or overridden contracts;
- direct callers that may need adaptation;
- tests calling the target or its direct callers;
- direct callees;
- referenced type definitions;
- exact source slices and SHA-256 evidence;
- dynamic or conflicting blind spots;
- candidates omitted by the configured budget;
- a deterministic map digest.

The default traversal is intentionally one hop. Context selection is deterministic and methods are never truncated halfway through.

## Keep a map current

A full semantic build of a large repository costs minutes. `refresh` re-analyses only the files
whose hash moved plus the ones that appeared since the last build, drops deleted ones, and patches
the result into the existing map:

```bash
vendor/bin/agent-map refresh --root=. --index=.agent-map/php-symbols.json
```

It reports `Index is up to date` and skips the analysis entirely when nothing changed. Without an
explicit `--paths`, new files are looked for in the directories the map already covers.

An incremental build refuses to mix semantic backends. If PHPStan availability changed since the existing map was built, run a full `build` so every carried file and relation has one backend identity.

Relations are keyed by their source file, so edges pointing *into* a refreshed file keep the shape
they had at their own last analysis. Rebuild fully now and then to make incoming edges exact.

### Repository status

```bash
vendor/bin/agent-map stale
vendor/bin/agent-map changed --base=main
vendor/bin/agent-map summary
vendor/bin/agent-map stats
```

`stale` compares current SHA-256 hashes with the map. `context` refuses to materialize source from a stale map.

## Output formats

Read commands support:

```text
text
json
markdown
toon
```

Text is the compact human/agent default. JSON is the normal integration format. TOON is useful when the result will be inserted into model context.

## Library API

The CLI is an inspection layer. Other `agent-*` packages should compose PHP objects directly:

```php
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Index\IndexReader;

$map = (new IndexReader())->read('.agent-map/php-symbols.json');
$plan = (new EditContextPlanner())->plan(
    map: $map,
    target: 'App\\Service\\UserService::save',
);
```

`agent-loop` should not shell out to `agent-map` and scrape formatted text. Humans have invented enough avoidable protocols already.

## Generated files

Recommended `.gitignore` entry:

```gitignore
.agent-map/
```

Commit a map only when a repository explicitly wants a versioned snapshot.

## Evidence

[Does bounded Map navigation reduce LLM reading?](docs/dogfood/map-navigation-evidence.md) replays
three already-solved PHP issues against a grep/read baseline, the projection `agent-loop` builds
today, and agent-map's existing exact surfaces, and records where each one helps, where it costs
more than it returns, and which capabilities nothing consumes. The harness is in `tools/dogfood/`.

## Development

```bash
composer install
composer ci
```

CI validates Composer metadata, PHPUnit, and PHPStan on supported PHP versions.
