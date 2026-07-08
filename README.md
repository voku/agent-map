# agent-map

Compact PHP symbol maps for coding-agent navigation.

`agent-map` builds a small JSON index of PHP files, symbols, and methods, then
answers targeted questions such as "where is this class?", "what changed?", and
"what tests look nearby?". It is designed for token hygiene: agents should find
the right files before reading large chunks of a repository.

agent-map helps agents find the right files faster.
It does not decide what the repository has learned.

## Why This Exists

Coding agents often start by running broad searches and reading whole files.
That works, but it wastes context. `agent-map` gives agents a boring first stop:

```bash
vendor/bin/agent-map related EvidenceValidator
vendor/bin/agent-map file src/EvidenceValidator.php
vendor/bin/agent-map changed --base=main
```

Use the output to choose the smallest useful file or range to inspect next. Do
not paste the generated index into a prompt.

## What It Is Not

- Not a memory system.
- Not an LLM caller.
- Not a daemon.
- Not a database.
- Not a PHPStan replacement.
- Not an `agent-loop` or `agent-learning` dependency.

## Boundaries

`agent-map` builds and queries a compact PHP symbol map.

`agent-loop` may later orchestrate workflows and call `agent-map`.

`agent-learning` stores validated findings, proposals, and decisions. It must
not own repo maps.

`agent-recall-compiler` may later use map output to select context.

PHPStan remains the authoritative correctness/type validation gate.

Mago is an optional fast parser/toolchain backend.

RTK handles shell-output compression separately.

Mago guides agent navigation.
PHPStan judges agent claims.

## Requirements

- PHP 8.3 or newer
- Composer
- Optional: [Mago](https://github.com/carthage-software/mago) for the Mago parse gate

## Installation

In a project:

```bash
composer require voku/agent-map --dev
```

When working from this repository checkout:

```bash
composer install
```

For root-checkout development, Composer creates `vendor/bin/agent-map` through
the package post-install/update script. As a dependency, Composer exposes the
package binary normally.

## Quick Start

Build an index:

```bash
vendor/bin/agent-map build \
  --root=. \
  --paths=src,tests \
  --out=.agent-map/php-symbols.json \
  --backend=token
```

Inspect the map:

```bash
vendor/bin/agent-map summary
vendor/bin/agent-map query EvidenceValidator
vendor/bin/agent-map related EvidenceValidator
vendor/bin/agent-map file src/EvidenceValidator.php
vendor/bin/agent-map stale
```

For changed work:

```bash
vendor/bin/agent-map changed --base=main
```

## Commands

### `build`

Scans PHP files and writes the JSON index.

```bash
vendor/bin/agent-map build \
  --root=. \
  --paths=src,tests \
  --out=.agent-map/php-symbols.json \
  --backend=token \
  --exclude='~Generated.*\.php$~'
```

Options:

- `--root`: repository root. Defaults to the current working directory.
- `--paths`: comma-separated paths relative to root. Defaults to `.`.
- `--out`: output JSON file. Defaults to `.agent-map/php-symbols.json`.
- `--backend`: `token` or `mago`. Defaults to `token`.
- `--exclude`: repeatable PHP regex applied to normalized relative and absolute paths.
- `--workers`: concurrent `mago` processes to run when `--backend=mago`. Defaults to `1`. Mago has no batch mode, so each file is its own process; on large repos this is the difference between minutes and seconds. Ignored by the `token` backend.

Default excludes:

- `vendor/`
- `.git/`
- `node_modules/`
- `var/cache/`

Invalid exclude regexes fail before scanning.

### `query <term>`

Finds files by file path, symbol name, fully-qualified name, or method name.

```bash
vendor/bin/agent-map query EvidenceValidator
```

### `file <path>`

Shows indexed symbols for one file.

```bash
vendor/bin/agent-map file src/EvidenceValidator.php
```

### `related <term>`

Finds likely related files without pretending to be a semantic graph.

It currently combines:

- exact symbol/file/method matches
- likely test files with matching basename
- same-namespace files
- files that mention the term

```bash
vendor/bin/agent-map related EvidenceValidator
```

### `changed`

Shows changed PHP files against a base branch plus staged and unstaged working
tree PHP changes.

```bash
vendor/bin/agent-map changed --base=main
```

This command requires the index root to be a Git repository.

If the base comparison fails, `changed` warns and still reports working-tree
changes when possible.

### `summary`

Prints a compact repository overview.

```bash
vendor/bin/agent-map summary
```

### `stats`

Prints map size, symbol/method counts, and largest indexed files.

```bash
vendor/bin/agent-map stats
```

### `stale`

Checks whether indexed files changed or disappeared.

```bash
vendor/bin/agent-map stale
```

Exit codes:

- `0`: index is fresh
- `1`: one or more indexed files are stale or missing

## Shared Options

Most read commands accept:

- `--index`: index path. Defaults to `.agent-map/php-symbols.json`.
- `--format`: `text`, `json`, `markdown`, or `toon`. Defaults to `text`.
- `--limit`: maximum files/rows. Defaults to `20`.
- `--symbol-limit`: maximum symbols shown per file. Defaults to `10`.
- `--method-limit`: maximum methods shown per symbol. Defaults to `10`.

Examples:

```bash
vendor/bin/agent-map query Service --limit=20
vendor/bin/agent-map related Service --symbol-limit=5 --method-limit=5
vendor/bin/agent-map query EvidenceValidator --format=toon
```

Text is the default because it is compact for agents. JSON is opt-in and should
mostly be used by scripts. TOON output is provided by `helgesverre/toon`.

`query`, `file`, `summary`, `related`, `changed`, and `stats` warn when the
index is stale. They still return results so agents can keep moving, but the
right fix is to rebuild the map.

## Mago Backend

The token backend uses PHP's `token_get_all()` and works without external
tools.

The Mago backend runs Mago as a parse gate, then uses the token extractor for
symbols:

```bash
vendor/bin/agent-map build --backend=mago
```

If `mago` is missing or parsing fails, the command fails clearly. It does not
silently fall back to `token`.

Mago has no way to parse multiple files in one process, so agent-map spawns
one `mago` process per file. On a large repository that is slow if run
sequentially; pass `--workers` to run several `mago` processes concurrently:

```bash
vendor/bin/agent-map build --backend=mago --workers=8
```

## Index File

The JSON index stores:

- relative paths
- file mtimes and SHA1 hashes
- namespace
- symbols
- line numbers
- methods and visibility

It does not store source code or AST blobs.

Recommended `.gitignore` entry:

```gitignore
.agent-map/
```

Commit the generated index only when a project explicitly wants that.

## Makefile Template

Projects can include [`Makefile.agent-map.mk`](Makefile.agent-map.mk) to expose
stable agent-facing commands:

```makefile
include Makefile.agent-map.mk
```

Then agents can use:

```bash
make ai-map-build
make ai-map-stale
make ai-map-summary
make ai-map-query q=EvidenceValidator
make ai-map-file f=src/EvidenceValidator.php
make ai-map-changed base=main
make ai-map-related q=EvidenceValidator
make ai-map-stats
```

Optional Make variables mirror the CLI read controls:

```bash
make ai-map-query q=Service limit=10 symbol_limit=5 method_limit=5
make ai-map-related q=EvidenceValidator format=toon
```

Project defaults can be overridden with:

```makefile
AGENT_MAP_PATHS = src,tests,bin
AGENT_MAP_BASE = develop
AGENT_MAP_LIMIT = 10
```

See [`docs/agent-usage.md`](docs/agent-usage.md) for an `AGENTS.md`-ready
token hygiene snippet.

## Development

Install dependencies:

```bash
composer install
```

Run tests and static analysis:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Validate Composer metadata:

```bash
composer validate --strict
```

## License

MIT
