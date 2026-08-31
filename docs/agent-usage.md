# Agent usage

Build the map before asking a coding agent to inspect broad portions of the repository:

```bash
vendor/bin/agent-map build --root=. --paths=src,tests --out=.agent-map/php-symbols.json
```

## Known PHP target: resolve before searching

When the coding task already names the class, method, or function to change, do not make the model rediscover it with repository-wide text search. Resolve the exact identity first:

```bash
vendor/bin/agent-map scope 'App\Service\Foo::bar' --format=toon
```

`scope` is the cheap exact surface: it resolves one unambiguous PHP target, reports its source range and symbol identity, and inspects calls inside that range. If a short name is ambiguous, retry with the fully qualified target rather than selecting a fuzzy winner.

For a planned method edit, expand only the bounded working set needed for that target:

```bash
vendor/bin/agent-map context 'App\Service\Foo::bar' --format=toon
```

The context result is deterministic repository evidence. It can include the primary method, contracts/overrides, direct callers that may need adaptation, direct callees, relevant type definitions, tests, blind spots, omissions, source hashes, and bounded source slices. Feed it into `agent-recall-compiler` together with task guidance and tool instructions. Do not ask the model to repeat map discovery unless the result is incomplete for the requested change.

Use exact relation commands only when the relation itself is needed beyond the context projection:

```bash
vendor/bin/agent-map callers 'App\Service\Foo::bar' --format=toon
vendor/bin/agent-map callees 'App\Service\Foo::bar' --format=toon
```

## Unknown target: narrow first

Use broader Map navigation only when the task has not identified an exact target yet:

```bash
vendor/bin/agent-map query Foo --format=toon
vendor/bin/agent-map related Foo --format=toon
```

Ranked Search is a seed generator, not an exact identity oracle. Literal strings, configuration, templates, filenames, and other source shapes outside Map's semantic model remain appropriate native text-search tasks.

## Requested structural changes

The coding agent owns intent. Once it has decided which concrete code identity to change and what the requested result is, prefer a governed Map plan over hand-written repository-wide replacement:

```bash
vendor/bin/agent-map rename-plan 'App\Service\Foo::bar' 'renamedBar' --format=toon
vendor/bin/agent-map parameter-rename-plan 'App\Service\Foo::bar' '$old' '$new' --format=toon
vendor/bin/agent-map function-rename-plan 'App\helper' 'renamedHelper' --format=toon
vendor/bin/agent-map property-rename-plan 'App\Service\Foo::$value' 'renamedValue' --format=toon
vendor/bin/agent-map class-rename-plan 'App\Service\Foo' 'RenamedFoo' --format=toon
vendor/bin/agent-map class-constant-rename-plan 'App\Service\Foo::VALUE' 'RENAMED_VALUE' --format=toon
vendor/bin/agent-map method-removal-plan 'App\Service\Foo::obsolete' --format=toon
vendor/bin/agent-map property-removal-plan 'App\Service\Foo::$obsolete' --format=toon
vendor/bin/agent-map class-constant-removal-plan 'App\Service\Foo::OBSOLETE' --format=toon
vendor/bin/agent-map class-move-plan 'App\Legacy\Foo' 'App\Service\Foo' --format=toon
```

Renaming and relocating are separate contracts on purpose. `class-rename-plan` changes the name inside
the current namespace; `class-move-plan` keeps the name and changes only the namespace, deriving the
destination path from the project's declared Composer PSR-4 mappings. A request that would do both is
rejected rather than silently widened - rename first, then move the result.

Do not hard-code this list. Ask which contracts the installed version proves, and which map backend
each needs:

```bash
vendor/bin/agent-map plan-capabilities --format=json
```

Plans are read-only evidence, not edit authority. The mutation host must re-check status, provenance, hashes/ranges, expected source, blockers, review-required evidence, and move preconditions before writing.

Branch on the status before anything else. `blocked` publishes no edits and no moves at all and exits
`1`; `review_required` publishes exact edits alongside evidence PHP source cannot settle; only `safe`
means every consequence agent-map can observe is covered. A blocked plan is never a partially
applicable one.

The intended coding-agent path is therefore:

```text
known intent
→ scope
→ context
→ callers/callees only when needed
→ specific governed change plan when available (discovered, not assumed)
→ host-owned mutation and validation
```

Do not add or consume Map as a refactoring recommendation engine. Map should make an already-requested code change cheaper, smaller, and more deterministic than repeated `grep`, broad reads, or `sed`-style replacement.

Check freshness explicitly when the working tree may have changed since the map was built:

```bash
vendor/bin/agent-map stale
```

Rules:

- rebuild when `stale` reports changed hashes;
- use fully qualified class names when target resolution is ambiguous;
- treat `dynamic` and `multiple_targets` relations as blind spots;
- do not claim impact analysis is complete when candidates were omitted by the context budget;
- use the PHP API from `agent-loop`, not CLI-output parsing;
- prefer existing exact Map surfaces over adding a parallel command with the same semantics;
- discover governed contracts through `plan-capabilities` instead of assuming a command exists.
