# Pinned dependency set

`composer.lock.pinned` is a full `composer.lock` resolved from `composer.json`
at base `b8ecad69c6514514b40869e0a643b19fc019ebcf`.

    sha256  2504f36bb16a168111aa309107c0460fc9c22b86618fca6527c3d28f66c639f6
    resolved 2026-08-29
    key pin  phpstan/phpstan 2.2.9 @ 13d6b4f347bad222da436580c8304fa6f83e6bd0

## Why this file exists

`voku/agent-map` does not commit a lock file — `composer.lock` is in
`.gitignore` — and every requirement is a floating caret:

    "helgesverre/toon": "^3.1"
    "voku/simple-php-code-parser": "^0.22"
    "voku/stop-words": "^2.0"
    "phpstan/phpstan": "^2.2"
    "phpunit/phpunit": "^11.0"

That is correct for a library, and fatal for a strict A/B: two arms run days
apart resolve independently, and the integrity gate then fails on dependency
drift for a reason that has nothing to do with navigation policy.

`phpstan/phpstan` in particular is load-bearing for *this* task — the graded
behaviour reads `InstalledVersions::getReference('phpstan/phpstan')` — so its
resolved reference is not an incidental detail.

Both arms must install from the same lock:

```bash
cp experiments/nav-substitution-01/pinned/composer.lock.pinned composer.lock
composer install
```

The file is named `.pinned` because the bare pattern `composer.lock` in
`.gitignore` matches at any depth and would otherwise silently exclude it.

## Status: CANDIDATE, not verified

This lock was resolved in this session. It is **not** known to match the
dependency set the Conventional arm actually installed — that arm's lock
digest was recorded during the run but was not carried into this session.

So it makes every *future* pair reproducible, and it does not retroactively
make the current pair reproducible. What to do about that is the open decision
in `../README.md`.
