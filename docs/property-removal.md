# Property removal planning

`agent-map property-removal-plan` publishes read-only evidence for deleting one provably unused private PHP property. It never changes source files.

```bash
agent-map property-removal-plan 'App\Service::$obsolete' --format=json
```

## Contract

The command publishes `property_removal_plan` contract version `1.0` with the same evidence categories used by the existing governed plan family:

- `status`: `safe`, `review_required`, or `blocked`;
- map provenance and analysis fingerprint;
- exact deletion edits with source SHA-256, inclusive byte range, expected source and empty replacement;
- review-required blind spots;
- machine-distinct stale evidence;
- semantic blockers;
- explicit non-observable boundaries.

Blocked plans publish zero edits. A mutation host must still re-check provenance, current source hashes, exact ranges and expected source immediately before writing.

## Safe envelope

A plan is automatically `safe` only when all of the following are proven from current source and a PHPStan-backed map:

- the target has exactly one PHPStan-resolved property declaration;
- the declaration is private, non-static and contains exactly one property;
- it is not a promoted property and has no property hooks;
- the owner does not use traits and does not define Doctrine-style `loadMetadata()`;
- there are zero observed semantic `property_access` relations to the target;
- no dynamic or magic-property evidence creates a review requirement;
- the complete declaration can be mapped to an exact whole-line deletion without consuming neighboring source.

Attributes and PHPDoc are included in the exact deletion range but make the plan `review_required`, because they can carry runtime/framework/tooling metadata beyond semantic property-access evidence.

## Deliberate difference from Rector

The safety inventory is adapted from Rector's `RemoveUnusedPrivatePropertyRector` at immutable upstream commit `cbeefaa869f3c5a8721af602b887c242b18741fd`; see `THIRD_PARTY_NOTICES.md` for the retained MIT notice.

Rector can also remove write-only private properties and rewrite/remove assignments to them. `property_removal_plan@1.0` deliberately does **not** implement that behavior. Current agent-map property relations prove semantic property identity but do not publish a sufficiently strong read-vs-write access contract. Therefore any observed access—including an assignment—blocks automatic removal.

That restriction is intentional. A later contract may support write-only elimination only after the map can prove access mode and the required expression-preservation semantics without textual guessing.

## Explicit boundaries

The plan does not claim to observe or rewrite:

- reflection and string-based property lookup;
- serialization metadata and framework/non-PHP configuration;
- PHPDoc/arbitrary strings as semantic property usage;
- PHP outside the indexed map scope;
- write-only assignment transformations.

Static, public/protected, multi-property, hooked, trait-backed, Doctrine `loadMetadata()`, stale, conflicting, and unsafe same-line declarations fail closed.
