# Class rename planning

`agent-map class-rename-plan` builds a **read-only** plan for renaming one PHP class inside its current namespace.

```bash
agent-map class-rename-plan 'App\Service\OldClass' NewClass --format=json
```

The command never edits or moves files. It projects what a mutation host may apply after validating the complete pre-edit evidence set.

## Why this can work without PHPStan

Method dispatch needs semantic receiver/override evidence, so method rename requires a PHPStan-backed map. Static PHP class names are different: imports, type declarations, `new`, `instanceof`, inheritance, attributes, static references and `::class` references are syntactically name-resolvable.

Class rename therefore uses the names-resolved `Simple-PHP-Code-Parser` AST for exact source identity and works with both:

- `simple-php-code-parser+structural-only`
- `simple-php-code-parser+phpstan`

PHPStan may enrich the map, but it is not required merely to prove a static class-name token.

## Plan states

- `safe`: every observed static PHP class-name token maps to an exact byte range, the replacement class identity is free, and any conventional file move is deterministic.
- `review_required`: exact source edits are available, but bounded evidence remains that cannot yet be rewritten safely, such as PHPDoc references, exact class-name strings, unconventional source filenames, or multiple type declarations sharing the renamed class file.
- `blocked`: no mutation plan is published because the source map is stale, the target is ambiguous, the replacement class/file already exists, a namespace move was requested, or exact token evidence cannot be produced.

## Exact edits

Each source edit contains:

- indexed source SHA-256;
- file path;
- exact start/end byte positions;
- source line range;
- expected source token;
- replacement token;
- role (`class_declaration`, `class_import`, or `class_reference`);
- parser-resolution identity.

Imports with aliases only rewrite the imported FQN. Alias usages stay unchanged:

```php
use App\OldClass as Service;

new Service();
```

becomes a plan for:

```php
use App\NewClass as Service;

new Service();
```

The alias itself is not guessed to be the class name.

## File move evidence

When the class is declared in `OldClass.php`, the plan projects a same-directory move to `NewClass.php`. The move carries the same pre-edit source SHA-256 as the source edits.

A mutation host must validate **all** edit and move preconditions against one pre-edit snapshot before changing anything. Applying half the plan and then validating the rest would turn evidence into interpretive dance.

If the class file does not follow the class basename convention, the plan does not invent an autoload path. It becomes `review_required` instead.

## Deliberate limits

The first slice is same-namespace only. Namespace moves need a separate contract because they can alter directory layout, Composer PSR-4 mappings and references outside PHP source.

The planner does not automatically rewrite:

- PHPDoc type references;
- dynamically constructed class names;
- reflection/container/configuration strings beyond exact literals surfaced as review evidence;
- YAML, XML, templates, generated metadata, or other non-PHP artifacts;
- Composer/autoloader rules beyond the deterministic same-directory basename move.

These are surfaced as review evidence or explicit `not_observable` limits instead of being silently treated as covered.
