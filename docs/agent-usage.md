# Agent usage

Build the map before asking a coding agent to inspect broad portions of the repository:

```bash
vendor/bin/agent-map build --root=. --paths=src,tests --out=.agent-map/php-symbols.json
```

For a planned method edit, use:

```bash
vendor/bin/agent-map context 'App\Service\Foo::bar' --format=toon
```

The context result is deterministic repository evidence. Feed it into `agent-recall-compiler` together with task guidance and tool instructions. Do not ask the model to repeat map discovery unless `blind_spots` says the static result is incomplete.

Useful inspections:

```bash
vendor/bin/agent-map callers 'App\Service\Foo::bar'
vendor/bin/agent-map callees 'App\Service\Foo::bar'
vendor/bin/agent-map query Foo
vendor/bin/agent-map stale
```

Rules:

- rebuild when `stale` reports changed hashes;
- use fully qualified class names when target resolution is ambiguous;
- treat `dynamic` and `multiple_targets` relations as blind spots;
- do not claim impact analysis is complete when candidates were omitted by the context budget;
- use the PHP API from `agent-loop`, not CLI-output parsing.
