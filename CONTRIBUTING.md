# Contributing

Thanks for considering a contribution to `voku/agent-map`.

## Scope

`agent-map` builds compact, deterministic PHP repository symbol maps for
coding-agent navigation and context selection. It reconciles structural
declarations from AST parsing with semantic relationships from PHPStan.

## Development setup

```bash
git clone https://github.com/voku/agent-map.git
cd agent-map
composer install
```

## Before opening a PR

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan at level max
composer ci        # Runs composer validate --strict, test, and phpstan
```

All checks must pass cleanly.

## Code style

- `declare(strict_types=1)` in every PHP file.
- `final` classes and `readonly` value objects wherever applicable.
- Strict typing with zero PHPStan errors.
- Tests located in `tests/` mirroring the `src/` directory structure.
- Clear commit messages and focused pull requests.
