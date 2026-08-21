# Rename-plan provenance size dogfood

Measured 2026-08-21 before freezing the `method_rename_plan` 1.0 contract.

## Question

Does repeating every mapped `<path, sha256>` pair in `provenance.source_hashes` earn its output cost, or can the contract preserve the same owner boundary with complete map identity and hashes only on mutation evidence?

## Method

Three repositories were mapped with the structural backend so PHPStan availability could not affect the projection. Each `rename-plan --format=json` was expected to be blocked by the structural backend; this is intentional because provenance is emitted independently of semantic plan status. Repositories were checked out at the revisions below and no dependencies were installed inside them.

For each pretty-printed plan, `total` is the exact output file size. `provenance` and `source_hashes` are the byte lengths of those values independently encoded with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`. The latter two are attribution measurements, not additive partitions of `total`.

| Repository | Revision | Role | Mapped PHP files | Total bytes | Provenance bytes | `source_hashes` bytes | Hash share of total |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| `voku/agent-map` | `7acd72c` | owner/self-dogfood | 185 | 24,666 | 22,794 | 21,512 | 87.2% |
| `voku/simple-php-code-parser` | `bc1f714ed683` | medium repository | 76 | 11,153 | 9,689 | 8,903 | 79.8% |
| `voku/portable-ascii` | `b805306507d4` | larger real consumer | 202 | 25,884 | 23,954 | 22,664 | 87.6% |

## Decision

The projection is dominant in all three shapes. Do not serialize all mapped source hashes in every plan.

The contract instead separates:

- **complete map identity:** `map_digest` plus `analysis_fingerprint.source_digest` when available;
- **mutation evidence:** the existing per-edit `source_sha256`, exact range, and expected token;
- **freshness validation:** the host proves current-map readiness through the owner API immediately before applying, then validates each edit hash/token.

This keeps a changed, previously unrelated caller from being silently accepted: changing mapped PHP invalidates current-map readiness. It avoids paying an 80-88% projection tax on every plan merely to duplicate identity already represented by the map digest and source digest.

## Reproduction outline

```bash
agent-map build --root <repo> --backend structural --out <map.json>
agent-map rename-plan '<existing Class::method>' replacement --index <map.json> --format=json > <plan.json>
```

The byte calculation is deliberately ordinary `strlen(json_encode(...))`; no tokenizer, compression, or estimated token conversion is involved.
