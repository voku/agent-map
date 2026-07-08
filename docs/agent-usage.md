# Agent Token Hygiene

Before reading broad files or running grep loops, prefer compact repo-map
commands.

## Repository Map

Build or refresh the map:

```bash
make ai-map-build
```

Check freshness:

```bash
make ai-map-stale
```

Find symbols/files:

```bash
make ai-map-query q=EvidenceValidator
make ai-map-file f=src/EvidenceValidator.php
make ai-map-related q=EvidenceValidator
```

For changed work:

```bash
make ai-map-changed base=main
```

Get compact overview and size hints:

```bash
make ai-map-summary
make ai-map-stats
```

Keep broad queries small:

```bash
make ai-map-query q=Service limit=10 symbol_limit=5 method_limit=5
make ai-map-related q=EvidenceValidator format=toon
```

## Rules

- Do not dump `.agent-map/php-symbols.json` into the prompt.
- Use map output to choose the smallest relevant file/range.
- Use `limit`, `symbol_limit`, and `method_limit` when a Make query name is broad.
- Use RTK for noisy shell commands.
- Use PHPStan as correctness gate, not agent-map.
- Use ctx only for historical agent-session evidence.
