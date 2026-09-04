# agent-map

[![Build Status](https://github.com/voku/agent-map/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-map/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-map/v/stable)](https://packagist.org/packages/voku/agent-map)
[![Total Downloads](https://poser.pugx.org/voku/agent-map/downloads)](https://packagist.org/packages/voku/agent-map)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-map/d/monthly)](https://packagist.org/packages/voku/agent-map)
[![License](https://poser.pugx.org/voku/agent-map/license)](https://packagist.org/packages/voku/agent-map)
[![PHP Version Require](https://poser.pugx.org/voku/agent-map/require/php)](https://packagist.org/packages/voku/agent-map)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-map?style=flat-square)](https://github.com/voku/agent-map/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/voku/agent-map?style=flat-square)](https://github.com/voku/agent-map/network/members)

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

[Stability policy](docs/stability.md) classifies every public surface - stable, supported-conditional,
experimental, diagnostic or subtraction candidate - and states what 1.0 freezes. Read it before
depending on a command.

### When to use it

- The PHP identity to change is already known and you want its exact location, contracts, callers and
  dependencies without reading whole files.
- A change is mechanical - a rename, a removal, a namespace move - and you want exact byte-range
  edits with preconditions instead of a text substitution that half-works.
- A question is about PHP structure: what calls this, what does this depend on, what breaks.

### When not to use it

- The answer is a literal string, a config key, a template, or a file name. That is a text-search
  shape, and `grep` wins; the map has nothing to add and costs a build.
- The repository has no map yet and the task touches one obvious file. Building a map to edit one
  known line is the expensive path.
- The question is about intent, design or history rather than structure. Map reports what the source
  says, not what it should have said.

Silence from a scoped query is scoped silence. "The map has no callers for this" is not "this has no
callers" - a structural-only map has no call edges at all, and every surface says so rather than
implying absence.

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
directory scope makes an unchanged rebuild close to free. `--exclude` stays exact without losing
that cache: agent-map derives PHPStan `excludePaths` from the files the map excludes and keeps
PHPStan on the original directory scope.

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

### Search when the target is not yet known

When the task names no PHP identity, ranked hybrid search turns prose into seeds. It is a **seed
generator**, not a location oracle - the exact commands above remain the way to confirm an identity.

```bash
vendor/bin/agent-map search-index build --index=.agent-map/php-symbols.json
vendor/bin/agent-map search 'why are trailing commas dropped' --limit=8
vendor/bin/agent-map search-index doctor
```

The index is derived state, not a second source of truth: `search-index build` refuses to run against
a stale map, `refresh` re-chunks only what moved, and `doctor` reports drift between the map snapshot
and the stored index. `--semantic` adds embedding-backed ranking when a corpus provider is available;
without it the ranking stays lexical.

Search is **conditional**: it needs a SQLite build with FTS5 and a configured search database, and it
reports that plainly instead of returning an empty result that looks like an answer. Literal strings,
configuration, templates and file-name questions stay native text-search shapes - see
[ADR 0001](docs/adr/0001-hybrid-search-is-a-derived-index.md).

### Discover architecture

```bash
vendor/bin/agent-map discover
vendor/bin/agent-map impact 'App\Service\UserService::save' --depth=3
```

`discover` derives evidence-backed repository orientation without requiring a search query. It reports entrypoint candidates, call hubs, orchestrators, type hubs, relation quality, and coupling across namespaces, directories, and files.

Namespaces are deliberately not the only architecture signal. PHP allows projects without namespaces, so path and file coupling remain available for flat and legacy codebases.

`impact` performs a bounded, cycle-safe reverse traversal and preserves relation evidence, path nodes, truncation, and `dynamic` / `multiple_targets` uncertainty instead of collapsing them into an opaque score.

Both are [experimental](docs/stability.md): they produce real output, but no consumer and no replay has yet measured that the output is worth its prompt cost.

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

### Plan safe PHP renames

Renaming a declaration with `sed` is how a repository acquires a half-renamed identity. The governed
rename family resolves one explicitly requested target and publishes exact, hash-bound byte edits
instead:

```bash
vendor/bin/agent-map class-rename-plan 'App\Service\OldName' NewName --format=json
vendor/bin/agent-map rename-plan 'App\Service\UserService::save' store --format=json
vendor/bin/agent-map parameter-rename-plan 'App\Service\UserService::save' '$old' '$new' --format=json
vendor/bin/agent-map property-rename-plan 'App\Service\UserService::$old' '$new' --format=json
vendor/bin/agent-map class-constant-rename-plan 'App\Service\UserService::OLD' NEW --format=json
vendor/bin/agent-map function-rename-plan 'App\old_helper' new_helper --format=json
```

Every plan in the family shares the same shape: a versioned contract type, a `safe` /
`review_required` / `blocked` status, `provenance` (map digest, effective backend, analysis
fingerprint), exact edits, blind spots, stale evidence, blockers, and an explicit `not_observable`
boundary. A `blocked` plan publishes no edits.

Which map a contract needs differs. Static class-name tokens are name-resolvable, so class renaming,
class-constant renaming and class moves work on a structural-only map. Method, parameter, property and
function renaming, and every removal contract, need semantic evidence and therefore a PHPStan-backed
map. Ask the registry rather than guessing - it covers all three families, and routing and discovery
read the same list, so an advertised contract is always routable:

```bash
vendor/bin/agent-map plan-capabilities --format=json
```

See [class rename](docs/class-rename.md) and [method rename](docs/method-rename.md) for the full
evidence, status and mutation-host validation semantics.

### Plan a class namespace move

Moving a class is not a rename: the file has to land where the autoloader expects the new identity,
and every reference that resolved through the old namespace changes meaning.

```bash
vendor/bin/agent-map class-move-plan 'App\Legacy\UserService' 'App\Service\UserService' --format=json
```

The destination path is derived from the project's declared Composer PSR-4 mappings and the manifest
identity is recorded as evidence; `composer.json` itself is never rewritten. The plan publishes the
namespace declaration edit, the affected imports and references, and one preconditioned file move.

References that resolved through the enclosing namespace are pinned to fully qualified names and
reported for review rather than by synthesizing new imports. Ambiguous autoload layouts, destination
collisions, grouped imports of the moved class, multi-symbol or multi-namespace files and namespaced
function fallbacks fail closed.

See [class move](docs/class-move.md) for the complete contract.

### Plan safe PHP removals

Avoid line-oriented `sed` edits when deleting PHP declarations. A PHPStan-backed map can produce a
whole-node, hash-guarded deletion for an unused private method:

```bash
vendor/bin/agent-map method-removal-plan 'App\Worker::obsolete' --format=json
vendor/bin/agent-map property-removal-plan 'App\Worker::$obsolete' --format=json
vendor/bin/agent-map class-constant-removal-plan 'App\Worker::OBSOLETE' --format=json
```

The plan includes the exact byte range and expected source, including associated PHPDoc and attributes,
but remains read-only. Observed calls, public/protected contracts, stale files, conflicting parser
evidence, traits, magic methods/dispatch, unresolved class-string static calls anywhere in indexed
source, and unsafe same-line source fail closed. Typed dynamic dispatch and method attributes are
surfaced for review rather than promoted to certainty.

The same read-only contract now covers unused private properties and class constants. Class-constant
plans adapt Rector's `RemoveUnusedPrivateClassConstantRector`: only a single private declaration can
be deleted, every indexed PHP file is AST-scanned for static fetches, and the plan includes the whole
declaration (PHPDoc and attributes included) rather than asking an agent to splice lines with `sed`.
Stale files and observed fetches fail closed. Attributes and PHPDoc require review. Reflection,
`constant()`, dynamic constant names, inherited or late-static lookup, and source outside the indexed
map are not observable; the plan lists them as explicit boundaries instead of proving them absent.

## Keep a map current

A full semantic build of a large repository costs minutes. A structural-only `refresh` re-analyses
only files whose hash moved, drops deleted ones, and patches the result into the existing map. A
PHPStan-backed `refresh` instead rebuilds its complete stored semantic scope through the structural
cache and lets PHPStan's dependency-aware result cache select changed files and semantic dependents:

```bash
vendor/bin/agent-map refresh --root=. --index=.agent-map/php-symbols.json
```

It reports `Index is up to date` and skips the analysis entirely when nothing changed. Without an
explicit `--paths`, new files are looked for in the directories the map already covers.

An incremental build refuses to mix semantic backends. If PHPStan availability changed since the existing map was built, run a full `build` so every carried file and relation has one backend identity.

The map records its PHPStan paths, exclude rules, and scan directories in the analysis fingerprint.
A normal PHPStan refresh reuses that recorded scope even when the caller omits flags; an explicit
scope, PHPStan configuration, or `composer.lock` change triggers a full semantic refresh. This
keeps call edges into a changed declaration exact without trusting source hashes alone.

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

`text` and `markdown` are human projections. `json` and `toon` are the machine boundary and are two
serializers of one model, never two semantic implementations. Governed plans therefore emit `text`,
`json` and `toon` and deliberately not `markdown`: a plan is consumed by a mutation host, not pasted
into a report.

## Plan status semantics

Every governed plan - rename, removal, move - reports exactly one status, and a host must branch on
it before doing anything:

| status | meaning | edits and moves | exit code |
| --- | --- | --- | --- |
| `safe` | Every consequence agent-map can observe maps to an exact byte range. | published | `0` |
| `review_required` | The exact edits are published, and bounded evidence remains that PHP source alone cannot settle - listed in `blind_spots`. | published | `0` |
| `blocked` | The plan cannot be proven. | **none** | `1` |

A blocked plan never publishes apparently applicable edits. That is the single rule the whole family
is built around: a partial mutation is worse than no mutation.

Alongside the status, every plan carries:

- `provenance` - map digest, effective backend, analysis fingerprint;
- `stale_evidence` - source that moved since the map was built, kept machine-distinct from semantic
  blockers because the recovery differs (refresh the map, versus rethink the change);
- `blockers` - why the plan is not safe;
- `not_observable` - what the contract structurally cannot see, stated rather than implied.

Every edit carries the pre-edit source SHA-256 and an exact byte range; every move carries the same
hash and requires the destination to be absent. Validate the complete precondition set against one
pre-edit snapshot before applying anything.

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

The supported consumer boundary is:

- `Index\IndexReader` / `Index\AgentMapIndex` for map reads and exact identity resolution;
- `Context\EditContextPlanner` for bounded edit context;
- the planners under `Rename\`, `Removal\` and `Move\`, all returning a `Plan\GovernedPlan`;
- `Plan\PlanCapability` via `agent-map plan-capabilities` to discover which contracts this version
  proves and which map backend each needs;
- `Cli\CliApplication` when a host genuinely needs to embed the command line.

Files below `.agent-map/` are package-owned state, not an interface. A consumer that reads them, or
parses CLI text, is depending on something that is free to change in a patch release.

## Makefile integration & PackageResources

The package includes a ready-to-use Makefile helper at `resources/make/agent-map.mk`. You can include it directly in your project's `Makefile`:

```makefile
-include vendor/voku/agent-map/resources/make/agent-map.mk
```

PHP tools and host integrations can resolve that resource path programmatically via `voku\AgentMap\PackageResources`:

```php
use voku\AgentMap\PackageResources;

$makeIncludePath = PackageResources::makeInclude();
// returns /path/to/vendor/voku/agent-map/resources/make/agent-map.mk
```

## Generated files

Recommended `.gitignore` entry:

```gitignore
.agent-map/
```

Commit a map only when a repository explicitly wants a versioned snapshot.

## Evidence

[Does bounded Map navigation reduce LLM reading?](docs/dogfood/map-navigation-evidence.md) replays
three already-solved PHP issues against a grep/read baseline, the projection from pinned `agent-loop`
revision `3b7190d`, and agent-map's existing exact surfaces, and records where each one helps, where it
costs more than it returns, and which capabilities nothing consumes. The harness is in `tools/dogfood/`.

Those per-capability verdicts feed the [stability policy](docs/stability.md), which is where a
capability's tier and its 1.0 direction are recorded.

## Development

```bash
composer install
composer ci
```

CI validates Composer metadata, PHPUnit, and PHPStan on supported PHP versions.
