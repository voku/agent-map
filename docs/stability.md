# Stability policy and public surface classification

This document is the pre-1.0 contract audit for `voku/agent-map`. It says what 1.0 will freeze, what
each public surface is worth today, and what still has to be decided.

It exists because pre-1.0 is the only cheap moment to delete something. After 1.0 an unused command
is still API.

## What agent-map is for 1.0

```text
PHP repository
    -> deterministic structural + semantic map
    -> freshness / provenance
    -> bounded structural navigation
    -> bounded edit context
    -> read-only, fail-closed mechanical refactoring plans
```

And explicitly not: an architecture advisor, a general repository search engine, an LLM orchestrator,
a mutation engine, or a generic refactoring framework. Mapping stays read-only with respect to the
analysed project; every plan is evidence, never authority to mutate.

That boundary is what 1.0 freezes. A capability that only makes sense on the other side of it does
not become stable by being useful.

## Stability tiers

| tier | meaning |
| --- | --- |
| **stable** | Frozen at 1.0. A breaking change needs a major version. |
| **supported, conditional** | Supported, but availability depends on something outside agent-map (a PHPStan-backed map, an FTS5 search database). Absence is a capability limit that must be reported, never an answer. |
| **experimental** | Public and usable, but may change or be removed in a minor release until real-task evidence justifies a stronger claim. |
| **diagnostic** | For humans and operators. The output shape is not a machine contract. |
| **subtraction candidate** | Scheduled for removal before 1.0 unless a consumer appears first. |

## Classification

Evidence column references [the navigation dogfood report](dogfood/map-navigation-evidence.md),
whose per-capability verdicts are the measured basis for anything below marked *unused* or
*unmeasured*.

### Core

| surface | CLI | library | tier | note |
| --- | --- | --- | --- | --- |
| map build / incremental refresh | `build`, `refresh` | `AgentMapBuilder`, `IndexWriter`, `IndexReader` | stable | Every consumer runs it. Backends must stay explicit; structural-only is a supported backend, not a silent fallback. |
| freshness / readiness | `stale` | `MapReadinessInspector`, `AgentMapIndex::staleEntries()` | stable | The precondition every other answer depends on. |
| exact identity resolution | via other commands | `AgentMapIndex::resolveMethod()`, `symbolById()`, `file()` | stable | The cheapest correct answer agent-map has. |
| bounded edit context | `context` | `EditContextPlanner`, `EditContextPlan`, `EditContextPolicy` | stable | The productive core: it produced every map range hit in the replay experiment, including the cross-file hit ranking alone missed. |
| exact callers / callees | `callers`, `callees` | `AgentMapIndex::incoming()/outgoing()` | supported, conditional | `calls` relations need a PHPStan-backed map. A structural-only map has no call edges; that is a capability limit, never evidence of no callers. |
| symbol / neighbour navigation | `query`, `file`, `related`, `changed` | `AgentMapIndex::query()` | stable | The most-cited commands in consumer skills, even though nothing consumes them automatically. |
| exact scope inspection | `scope` | `ScopeSelector`, `ScopeInspector` | stable | Unconsumed, and kept anyway: 1.3 KB for one method is the cheapest exact call listing in the package. Cheap exact primitives are not the same kind of surface as unproven derived ones. |
| artifact layout | `--out`, `--index`, `--database`, map artifact root | `MapArtifactPaths` | stable | `.agent-map/` is package-owned. An embedding host may remount the root; explicit paths stay authoritative. |
| embedding boundary | - | `Cli\CliApplication` | stable | The supported way to embed the CLI with a project root and map root. |

### Governed plan family

All fourteen plans across five families are **stable** contracts at version `1.0`, and share one envelope: `type`,
`contract_version`, `status`, `target_id`, `provenance`, `edits`, `blind_spots`, `stale_evidence`,
`blockers`, `not_observable`, plus contract-specific identity and, where it applies, `moves`.
`voku\AgentMap\Plan\GovernedPlan` declares the shared behaviour, the shared value objects
(`PlanProvenance`, `PlanEdit`, `PlanBlindSpot`, `PlanStaleEvidence`, `PlanMove`) live beside it, and
`tests/PlanContractShapeTest.php` pins the envelope.

| family | contract | command | needs PHPStan |
| --- | --- | --- | --- |
| rename | `class_rename_plan@1.0` | `class-rename-plan` | no |
| rename | `class_constant_rename_plan@1.0` | `class-constant-rename-plan` | no |
| rename | `function_rename_plan@1.0` | `function-rename-plan` | yes |
| rename | `method_rename_plan@1.0` | `rename-plan` | yes |
| rename | `parameter_rename_plan@1.0` | `parameter-rename-plan` | yes |
| rename | `property_rename_plan@1.0` | `property-rename-plan` | yes |
| removal | `method_removal_plan@1.0` | `method-removal-plan` | yes |
| removal | `property_removal_plan@1.0` | `property-removal-plan` | yes |
| removal | `class_constant_removal_plan@1.0` | `class-constant-removal-plan` | yes |
| move | `class_move_plan@1.0` | `class-move-plan` | no |
| move | `method_move_plan@1.0` | `method-move-plan` | yes |
| copy | `method_copy_plan@1.0` | `method-copy-plan` | yes |
| scaffold | `class_scaffold_plan@1.0` | `class-scaffold-plan` | no |
| scaffold | `method_scaffold_plan@1.0` | `method-scaffold-plan` | yes |

Shared invariants, all of them machine-checkable:

- `status` is exactly one of `safe`, `review_required`, `blocked`, and a plan carrying anything else
  cannot be constructed.
- A `blocked` plan publishes **no** edits and **no** moves. There is no partial mutation evidence.
  This is enforced in the constructor of every plan, not left to each planner's discretion: a blocked
  plan holding an edit or a move is not representable.
- Every path a plan names stays inside the project root. `PlanMove` refuses absolute paths, `..`
  segments and Windows drive paths rather than normalizing them into something that looks local.
- Evidence identity lives in `provenance` (map digest, effective backend, analysis fingerprint) and
  nowhere else. The pre-0.9 top-level `backend` / `map_digest` aliases were removed in 0.9.
- Stale source evidence is machine-distinct from semantic blockers, because the recovery differs.
- Every edit carries the pre-edit source SHA-256 and an exact byte range; every move carries the same
  hash and requires an absent destination.
- CLI exit code is `1` for a blocked plan and `0` otherwise, uniformly across all fourteen commands.
- `text` is a human projection; `json` and `toon` are two serializers of one model, never two
  semantic implementations. Plans deliberately do not emit `markdown`.

### Conditional, experimental and diagnostic

| surface | CLI | tier | why |
| --- | --- | --- | --- |
| ranked hybrid search | `search`, `search-index` | supported, conditional | Needs a configured search database and FTS5. Proven useful as a *seed generator*, not as a location oracle. Literal, config, template and filename questions remain native text-search shapes. |
| architecture discovery | `discover` | experimental | Real output, unmeasured benefit: it activates only when a task names no files and no targets, and no replay measured whether it helped. |
| bounded reverse impact | `impact` | experimental | No automated consumer; model-invoked only. 15.6 KB text / 53 KB JSON at depth 2 is a real prompt cost with no measured payoff yet. |
| graph ranking | - | **removed in 0.9** | `rank` had no consumer, appeared in no skill and was derivable from `callers`/`callees`. `GraphRanker` survives as an internal collaborator of `discover`; it is no longer public API. |
| temporal history | `history diff/coupling/claims/observe/show` | experimental | Derived evidence by [ADR 0002](adr/0002-temporal-history-is-derived-evidence.md); no automated consumer. |
| repository status | `summary`, `stats`, `changed` | diagnostic | Human orientation. The output shape is not a machine contract. |
| plan capability discovery | `plan-capabilities` | stable | Covers all fourteen contracts across the rename, removal, move, copy, and scaffold families. Routing and discovery read the same registry, so an advertised contract is always routable. |

## What 1.0 freezes

**Persisted map schema.** `schema_version`, backend identity, the analysis fingerprint, reconciliation
states, relation kinds and freshness semantics. A map written by 1.0.x is readable by 1.0.y.

The compatibility rule is explicit and enforced: `AgentMapIndex::SCHEMA_VERSION` is what this build
writes, `AgentMapIndex::SUPPORTED_SCHEMA_MAJOR` is what it reads. A map from any other major - older
or newer - is rejected on read with a rebuild instruction, because a partially understood map answers
"no callers" where it should answer "unknown". Additive minor versions within the supported major stay
readable.

**Plan contracts.** Contract versions, the status vocabulary, the meaning of `review_required` versus
`blocked`, edit hash/range semantics, JSON/TOON equivalence and exit codes, as listed above.

**Artifact ownership.** Generated state stays below the package-owned `.agent-map/` root, or below an
explicitly mounted artifact root. agent-map does not learn the layout policy of an embedding package.

**Library over CLI.** The PHP API is the canonical machine boundary. Other packages should consume
typed APIs, not scrape CLI text or read private map files. CLI output is a projection of the library
result, and the CLI's *human* text rendering is deliberately not frozen at the same strength as the
JSON/TOON projections.

**Optionality of PHPStan.** Structural-only remains a supported explicit backend. A selected PHPStan
backend that turns out to be unavailable fails explicitly rather than downgrading silently.

## 0.9 surface decisions

These each changed a public surface, so each was a decision rather than a cleanup. All six were
settled in 0.9; the reasoning is kept because the 1.0 gate asks whether every surface has a stated
reason to exist.

1. ~~**`search` / `search-index` are routable but undocumented.**~~ Resolved in 0.9: they were
   routable public commands listed in neither `agent-map help` nor the README. Documenting an
   existing public command is not a surface decision - leaving it hidden was the anomaly - so both
   now describe them as *supported, conditional*, with their activation preconditions stated.
2. ~~**`rank`.**~~ Resolved in 0.9: deleted. The command, its help, and its documented library API
   are gone; `GraphRanker` stays as an internal detail of `discover`. Deleting it before 1.0 cost
   nothing.
3. ~~**Capability discovery covers only renames.**~~ Resolved in 0.9: `rename-capabilities` was
   replaced by `plan-capabilities`, which covers all ten contracts and carries a `family` field. Every
   plan boundary implements one `PlanCliApplication` interface, and the routing list *is* the
   registry, so a contract cannot be reachable while being undiscoverable. Replacing the command
   rather than adding a second one keeps the rule that there is no parallel API.
4. ~~**Family-wide value objects still live under `Rename\`.**~~ Resolved in 0.9: they moved to
   `voku\AgentMap\Plan` as `PlanProvenance`, `PlanEdit`, `PlanBlindSpot`, `PlanStaleEvidence` and
   `PlanMove`. Machine output is unchanged; only the PHP type names and namespace moved, which is
   free before 1.0 and a major version after it.
5. ~~**Legacy map-read compatibility.**~~ Resolved in 0.9: it was dead code with a silent failure
   mode. `IndexReader` never checked `schema_version`, so a schema-1.x map was reconstructed from
   whichever fields happened to match. The supported schema range is now enforced on read, and the
   1.x-only readers it made unreachable (`sha1`, the legacy `return_type`, the `params` alias and
   string parameter entries) are gone.
6. ~~**`markdown` coverage is uneven.**~~ Resolved in 0.9 as a stated rule rather than a code
   change: `text` and `markdown` are human projections and are offered where a human reads the
   output (navigation, discovery, temporal); `json` and `toon` are the machine boundary and are
   offered everywhere. Governed plans emit `text`, `json` and `toon` and deliberately not
   `markdown` - a plan is consumed by a mutation host, not pasted into a report.

## The 1.0 gate

1.0 ships when all of these are true:

- [ ] no known temporary compatibility aliases remain;
- [ ] every public surface has an owner, a tier, and a stated reason to exist;
- [ ] the persisted map schema has explicit compatibility rules;
- [ ] every stable plan contract is versioned and shares the frozen envelope;
- [ ] a blocked plan provably never exposes applicable edits or moves;
- [ ] provenance and freshness semantics are consistent across every surface that reports them;
- [ ] structural-only and PHPStan-backed modes fail predictably rather than silently differing;
- [ ] the library API is the supported machine boundary, and no consumer reconstructs private paths;
- [ ] real dogfood exists for navigation, edit context, and each refactoring family, including moves;
- [ ] `agent-loop` and `agent-recall-compiler` consume a released agent-map, not a path repository or
      a development branch;
- [ ] one full 0.9.x cycle produced no required architectural rewrite;
- [ ] README, UPGRADING and CHANGELOG describe what the code actually does.

## Release shape

`0.10.0` completes the governed plan surface across all fourteen contracts and introduces the split
index format for high-performance navigation. Ahead of 1.0, the package focuses on dogfood proof,
fresh-consumer ergonomics, and compatibility hardening (fresh install, structural-only projects,
PHPStan-backed projects, large repositories, partial `--paths`, JSON/TOON parity, and Windows paths).

