# Architecture discovery

`agent-map` can derive architecture-oriented signals directly from the existing PHP map without asking an LLM to rediscover the repository first.

The discovery layer is intentionally evidence-backed. It does not invent subsystem names, architectural boundaries, or confidence scores. It works from persisted symbols, source locations, PHPStan-resolved relations, structural relations, and repository paths.

## Commands

### `discover`

```bash
vendor/bin/agent-map discover
vendor/bin/agent-map discover --limit=20 --format=json
```

`discover` provides a compact repository orientation without requiring a search query. It reports:

- entrypoint candidates;
- call hubs;
- orchestrators;
- type dependency hubs;
- namespace coupling;
- directory coupling;
- file coupling;
- relation quality and uncertainty counts;
- the deterministic map digest.

An entrypoint candidate is deliberately weaker than an entrypoint assertion. It is a method or function that calls into the indexed repository but has no indexed internal caller. Framework callbacks, reflection, generated dispatch, configuration-driven invocation, or code outside the indexed scope can still call it.

### `rank`

```bash
vendor/bin/agent-map rank --by=dependents --top=20
vendor/bin/agent-map rank --by=callers --kind=method --top=20
vendor/bin/agent-map rank --by=callees --format=toon
```

Supported metrics are:

- `dependents`: unique incoming dependency neighbours;
- `callers`: unique incoming `calls` neighbours;
- `dependencies`: unique outgoing dependency neighbours;
- `callees`: unique outgoing `calls` neighbours;
- `members`: contained members such as declared methods.

Scores count unique one-hop graph neighbours rather than raw relation rows. Repeated call sites between the same nodes therefore do not inflate the result.

Dynamic and multiple-target relations are reported separately as uncertainty instead of being silently treated as precise facts.

### `impact`

```bash
vendor/bin/agent-map impact 'App\Service\UserService::save'
vendor/bin/agent-map impact 'App\Service\UserService::save' --depth=3 --max-nodes=200 --format=json
```

`impact` walks dependency relations in reverse to find repository nodes that may depend on a changed method.

The traversal is:

- bounded by `--depth`;
- bounded by `--max-nodes`;
- cycle-safe;
- deterministic;
- source-backed;
- explicit about truncation;
- explicit about uncertain paths.

Each affected node retains relation kinds, evidence relation IDs, and `via_node_ids` describing the discovered path.

Uncertainty propagates through the path. If an affected node is reachable only through a `dynamic` or `multiple_targets` relation, the result remains uncertain at later depths. If the same node is independently reachable through a fully resolved path, the certain path is sufficient to establish that impact without pretending the uncertain path disappeared.

## PHP architecture signals

PHP namespaces are useful, but optional. `agent-map` therefore does not use namespaces as the only architecture boundary.

Discovery derives three independent coupling views:

1. **Namespace coupling** shows logical package/module relationships when namespaces exist.
2. **Directory coupling** shows physical source-tree relationships regardless of namespaces.
3. **File coupling** preserves useful structure even in flat or namespace-less legacy PHP projects.

For example, a project containing global classes in:

```text
legacy/web/Front.php
legacy/domain/Service.php
legacy/db/Repository.php
```

can still expose meaningful `legacy/web -> legacy/domain -> legacy/db` coupling even though every PHP symbol lives in the global namespace.

Directory and file paths are repository-relative and normalized before grouping, so path-based discovery is not tied to the host operating system's separator convention.

## Relation quality

The discovery report includes relation-quality counts. In particular, `dynamic` and `multiple_targets` relations remain visible as uncertainty.

This distinction matters for coding agents: an unresolved or ambiguous edge is evidence of a blind spot, not permission to convert a guess into a fact.

## Freshness

`discover`, `rank`, and `impact` require a fresh map. If indexed source hashes no longer match the repository, the command fails and asks for a refresh instead of combining current source with stale architecture data.

```bash
vendor/bin/agent-map refresh
vendor/bin/agent-map discover
```

A full rebuild remains useful periodically because incremental refreshes preserve incoming edges from files that were not themselves re-analysed.

## Output formats

The commands support the same read formats as the rest of the CLI:

```text
text
json
markdown
toon
```

Use text for compact inspection, JSON for integrations, Markdown for reports, and TOON when the result is intended for model context.

## Library API

Consumers inside the `agent-*` stack should prefer the PHP API over shelling out and parsing formatted output:

```php
use voku\AgentMap\Discovery\ArchitectureDiscovery;
use voku\AgentMap\Discovery\GraphMetric;
use voku\AgentMap\Discovery\GraphRanker;
use voku\AgentMap\Discovery\ImpactAnalyzer;
use voku\AgentMap\Index\IndexReader;

$map = (new IndexReader())->read('.agent-map/php-symbols.json');

$discovery = (new ArchitectureDiscovery())->discover($map, limit: 10);
$ranked = (new GraphRanker())->rank($map, GraphMetric::DEPENDENTS, limit: 10);
$impact = (new ImpactAnalyzer())->forMethod(
    map: $map,
    target: 'App\\Service\\UserService::save',
    maximumDepth: 2,
    maximumNodes: 100,
);
```

The goal is repository orientation and evidence-backed navigation, not an opaque architecture oracle. A coding agent should use these signals to decide where to inspect next and still verify the source before changing it.
