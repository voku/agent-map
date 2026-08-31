# Class move planning

`agent-map class-move-plan` builds a **read-only** plan for relocating one PHP class into another namespace.

```bash
agent-map class-move-plan 'App\Legacy\UserService' 'App\Service\UserService' --format=json
```

The command never edits source, never moves files and never rewrites `composer.json`. It projects what a mutation host may apply after validating the complete pre-edit evidence set.

Renaming and moving stay separate contracts. `class-rename-plan` changes the class name inside its current namespace; `class-move-plan` keeps the name and changes only the namespace. A request that does both is rejected rather than silently widened: rename first, then move the result.

## Why a namespace move is not a rename

A rename is a token change. A move changes the *resolution context* of the declaration, so three things that a rename never has to think about become load-bearing:

1. the file has to end up where the project's autoloader expects the new identity;
2. references that resolved through the old namespace instead of through an import change meaning;
3. the moved source itself resolved its own dependencies through the old namespace.

Contract `class_move_plan@1.0` covers exactly the part of that which is mechanically provable, and blocks the rest.

Like class renaming, this needs no PHPStan: static class-name tokens are name-resolvable, so the contract works on both `simple-php-code-parser+structural-only` and `simple-php-code-parser+phpstan` maps. What it does need is deterministic autoload evidence.

## PSR-4 evidence

The destination path is **derived**, not guessed. The planner reads the analysed project's `composer.json` and records the manifest identity in the plan:

```json
"autoload": {
    "manifest_path": "composer.json",
    "manifest_sha256": "sha256:...",
    "source_prefix": "App\\",
    "source_directory": "src",
    "source_section": "autoload",
    "destination_prefix": "App\\",
    "destination_directory": "src",
    "destination_section": "autoload",
    "destination_path": "src/Service/UserService.php"
}
```

Exactly one declared PSR-4 mapping has to explain the file's current location, and the destination identity has to be covered by one most-specific mapping with one directory. Anything else - a missing manifest, an uncovered destination prefix, equally specific competing mappings, or a classmap/files layout covering either end - blocks instead of inventing a path.

Composer configuration is evidence here, never a mutation target.

## Plan states

- `safe`: the namespace declaration, every affected import and every affected reference map to exact byte ranges, the destination identity and path are free, and the derived move is deterministic.
- `review_required`: exact edits are available, but bounded evidence remains that PHP source alone cannot settle - namespace-relative references, `__NAMESPACE__`, unqualified function or constant fallbacks, PHPDoc, exact class-name string literals, dynamic class names, a shadowed PSR-4 prefix, or a move that crosses `autoload`/`autoload-dev`.
- `blocked`: no edit or move is published at all. Stale source, an ambiguous target, a destination identity or file collision, a grouped import of the moved class, a file declaring more than one symbol, more than one namespace, a braced namespace, a global-namespace source, an unprovable autoload layout, or a namespaced function the move would rebind.

## Exact edits

Machine output is `class_move_plan` contract version `1.0`, with map digest, effective backend and analysis fingerprint under `provenance`; stale source evidence stays machine-distinct from semantic blockers.

Each edit carries the indexed source SHA-256, the file path, exact start/end byte positions, the source line range, the expected source token, the replacement token, a role, and the parser-resolution identity. Roles are:

- `namespace_declaration` - the moved file's own `namespace` statement;
- `class_import` - a `use` statement importing the moved class;
- `class_reference` - a reference to the moved class that is not covered by an import;
- `namespace_dependency` - a dependency the moved file resolved through its old namespace.

## The three reference forms

Only one of them needs rewriting:

```php
use App\Legacy\UserService;   // absolute import  -> rewritten once, aliases keep working
\App\Legacy\UserService::class; // fully qualified  -> rewritten in place
new UserService();            // covered by the import above -> untouched
```

A reference that resolved through the *enclosing namespace* is different. In a sibling class inside `App\Legacy`:

```php
public function make(): UserService
```

there is no import to fix, and after the move the short name would resolve to the wrong namespace. Contract 1.0 pins such a reference to the fully qualified destination identity rather than synthesizing a new `use` statement, and reports it as `namespace_relative_reference` review evidence. The same rule applies inside the moved file to its own dependencies (`namespace_relative_dependency`): they are pinned to the identity they have today, so the move cannot silently rebind them.

Synthesizing imports would mean inserting statements rather than replacing exact byte ranges. That is a different, larger contract; it is deliberately not part of 1.0.

## Unqualified function and constant calls

PHP falls back to the global namespace for unqualified function and constant names, so the moved file's bare calls are sensitive to the move. The planner separates three cases:

- a namespaced function in the source or destination namespace is indexed: **blocked**, because the call would rebind;
- the name exists as a PHP built-in function or constant: silent, because global resolution is unchanged;
- anything else: `namespace_fallback_reference` review evidence, because a namespaced declaration outside the indexed map cannot be ruled out.

## File move evidence

The plan projects exactly one preconditioned move, carrying the same pre-edit source SHA-256 as the edits and requiring the destination to be absent.

A mutation host must validate **all** edit and move preconditions against one pre-edit snapshot before changing anything. A half-applied move leaves a class whose file location and namespace disagree, which is precisely the failure this contract exists to prevent.

## Deliberate limits

The planner does not:

- suggest which classes should move;
- rewrite `composer.json`, framework configuration, YAML, XML, templates or generated metadata;
- rewrite PHPDoc, reflection strings, container identifiers or dynamic class names;
- search PHP outside the indexed map, including vendor and generated code;
- move a class into the global namespace, or out of one.

These stay `review_required` evidence or explicit `not_observable` limits instead of being silently treated as covered.
