# agent-map

Compact PHP repository symbol maps for coding-agent navigation.

`agent-map` builds a repository-local semantic map that coding agents can query without repeatedly
reading the whole codebase. It is deliberately a small evidence/navigation layer rather than a
framework or lifecycle authority.

## Installation

```bash
composer require --dev voku/agent-map
```

## CLI

```bash
vendor/bin/agent-map help
```

The map can be built with either structural analysis only or, when available, a PHPStan-backed
semantic backend.

```bash
vendor/bin/agent-map build --root=. --paths=src,tests
vendor/bin/agent-map search-index build --root=.
vendor/bin/agent-map search 'where is retry delay calculated?' --format=json
```

Use `--backend=structural` or `--backend=phpstan` when a caller needs an explicit backend choice.
Without PHPStan installed, structural maps remain available.

## Repository snapshots

Generated map/search artifacts are normally local working data. Keep them out of version control
unless the repository intentionally wants a committed snapshot.

Recommended `.gitignore` entry:

```gitignore
/.agent-loop/map/
```

Commit a map only when a repository explicitly wants a versioned snapshot.

## Evidence

[Does bounded Map navigation reduce LLM reading?](docs/dogfood/map-navigation-evidence.md) replays
three already-solved PHP issues against a grep/read baseline, the projection from the pinned
`agent-loop` revision, and agent-map's existing exact surfaces, and records where each one helps,
where it costs more than it returns, and which capabilities nothing consumes. The harness is in `tools/dogfood/`.

## Development

```bash
composer install
composer ci
```
