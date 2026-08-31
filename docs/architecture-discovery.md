# Architecture discovery

`agent-map` derives a deterministic PHP architecture map from the existing persisted semantic index before an agent knows which symbol or path to search for.

The architecture map is not an LLM summary. It is derived from PHP source evidence:

1. build an undirected weighted file-coupling graph;
2. infer progressively coarser file communities;
3. detect and down-weight likely cross-cutting files;
4. derive deterministic region labels from namespaces, directories, then file names;
5. expose the regions as navigation coordinates for drill-down and impact analysis.

The same `mapDigest` produces the same architecture. Namespace presence is never required.

## Architecture regions

File coupling uses the semantic relations already present in the map:

- `calls`;
- `instantiates`;
- `extends`;
- `implements`;
- `uses_trait`;
- `overrides`;
- `references_type`.

`dynamic` and `multiple_targets` edges contribute less weight than resolved relations instead of being silently treated as equally certain evidence. Repeated evidence is log-scaled so one noisy caller cannot dominate the graph merely by repeating the same relation many times.

Directory proximity is a deliberately weak structural prior. Files in small directories can remain discoverable even when legacy PHP has few useful semantic edges. Large flat directories use bounded neighbour links rather than an all-to-all structural clique, so physical fallback does not create quadratic graph growth.

Community detection is deterministic. Stable node order, tie-breaking, region IDs and adaptive resolution replace seed-dependent clustering. A small coherent repository that forms one community is retained as one system instead of being reported as `no architecture`.

When the graph supports several scales, regions form a hierarchy:

```text
system
  subsystem
    module
      files
```

Not every repository needs every level. The hierarchy follows observed coupling instead of manufacturing intermediate layers to satisfy a diagram.

## Region evidence

A region does not get a single opaque confidence score. It exposes the evidence an agent can inspect:

- internal and external coupling weight;
- boundary ratio and boundary strength;
- internal density;
- cross-cut score;
- namespace agreement;
- directory agreement;
- files that cross the region boundary;
- dominant semantic relation signals.

Likely cross-cutting files are identified from high weighted degree plus conservative utility/shared-path hints. Their edges are down-weighted during clustering and the files are reported separately. This reduces the tendency for logging, helpers, config or shared infrastructure to glue unrelated parts of a repository into one false subsystem.

## Commands

### `discover`

```bash
vendor/bin/agent-map discover
vendor/bin/agent-map discover --limit=20 --format=json
```

`discover` starts with the inferred architecture map, then reports lower-level orientation signals:

- architecture regions and hierarchy;
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

### Region drill-down

After the initial discovery pass, use a region label, exact region ID, or unique ID prefix instead of guessing a source directory:

```bash
vendor/bin/agent-map discover --region=Billing
vendor/bin/agent-map discover --region=region:abc123 --limit=30 --format=toon
```

The region view reports:

- its architecture path;
- boundary and structural evidence;
- dominant signals;
- member files;
- interface files;
- child regions.

Ambiguous labels or ID prefixes fail rather than choosing an arbitrary region.

### `impact`

```bash
vendor/bin/agent-map impact 'App\Service\UserService::save'
vendor/bin/agent-map impact 'App\Service\UserService::save' --depth=3 --max-nodes=200 --format=json
```

The core impact traversal remains architecture-agnostic, bounded, cycle-safe and evidence preserving. The CLI composes it with the derived architecture map and reports both views:

1. propagation grouped by the finest inferred architecture region;
2. exact impacted nodes with relation kinds, evidence IDs and `via_node_ids`.

The target also carries its architecture path. Region grouping organizes evidence; it never replaces the node-level path.

Uncertainty propagates through the exact traversal. If an affected node is reachable only through a `dynamic` or `multiple_targets` relation, the result remains uncertain at later depths. If the same node is independently reachable through a fully resolved path, that certain path establishes the impact while the ambiguous path remains represented by its evidence.

## PHP architecture signals

PHP namespaces are useful but optional. `agent-map` therefore treats them as one signal rather than the architecture model.

Three raw coupling views remain available alongside the inferred regions:

1. **Namespace coupling** shows logical relationships when namespaces exist.
2. **Directory coupling** shows physical source-tree relationships regardless of namespaces.
3. **File coupling** preserves direct dependencies even in flat or namespace-less legacy PHP projects.

For example, global classes in:

```text
legacy/web/Front.php
legacy/domain/Service.php
legacy/db/Repository.php
```

can still expose physical and semantic structure without inventing a namespace.

Repository paths are normalized before grouping, so path-based discovery is not tied to the host operating system's separator convention.

## Freshness

`discover` and `impact` require a fresh canonical map. If indexed source hashes no longer match the repository, the command fails and asks for a refresh instead of combining current source with stale architecture data.

```bash
vendor/bin/agent-map refresh
vendor/bin/agent-map discover
```

The inferred regions are currently derived from the persisted map on demand. They are not another source of truth. A derived architecture cache should only be introduced when measured runtime justifies the additional persistence and invalidation contract.

## Output formats

The commands support:

```text
text
json
markdown
toon
```

Use text for compact inspection, JSON for integrations, Markdown for reports, and TOON for bounded model context.

## Library API

Consumers inside the `agent-*` stack should prefer the PHP API over parsing formatted CLI output:

```php
use voku\AgentMap\Discovery\ArchitectureDiscovery;
use voku\AgentMap\Discovery\ArchitectureImpactAnalyzer;
use voku\AgentMap\Discovery\ImpactAnalyzer;
use voku\AgentMap\Index\IndexReader;

$map = (new IndexReader())->read('.agent-map/php-symbols.json');

$discovery = (new ArchitectureDiscovery())->discover($map, limit: 10);
$billing = $discovery->architecture->resolveRegion('Billing');

// Pure evidence traversal, unchanged for existing consumers.
$impact = (new ImpactAnalyzer())->forMethod(
    map: $map,
    target: 'App\\Service\\UserService::save',
    maximumDepth: 2,
    maximumNodes: 100,
);

// Add architecture paths and region buckets when that orientation is useful.
$architectureImpact = (new ArchitectureImpactAnalyzer())->forMethod(
    map: $map,
    target: 'App\\Service\\UserService::save',
    maximumDepth: 2,
    maximumNodes: 100,
);
```

The architecture map is a navigation model, not an architecture oracle. Agents should use it to choose the next bounded source read, then verify real source before editing.