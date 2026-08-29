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

## Status: MISMATCH — do not use for the strict pair

The Conventional arm's lock digest is now known:

    conventional arm   e465a5906b139b7e585b5428eee721fbca9351883dbb0cbd79272eaf39c17a3e
    this file          2504f36bb16a168111aa309107c0460fc9c22b86618fca6527c3d28f66c639f6

They differ, which is the expected result of two independent resolutions of
floating carets days apart.

A SHA-256 verifies a lock; it cannot reproduce one. There is no way to
regenerate the Conventional arm's exact lock from its digest — even resolving
the same package versions would not guarantee byte-identity, since lock content
also depends on the Composer version that wrote it.

So this file cannot serve the strict pair. Two options:

1. **File the real lock.** Copy the Conventional run's `composer.lock` to
   `../arms/conventional/composer.lock`, verify it hashes to `e465a590…`, and
   use *that* for MAP_FIRST. The strict pair survives.
2. **Accept the mismatch.** Run MAP_FIRST on this pin and classify the pair
   `DIAGNOSTIC_PILOT` per `../integrity/GATE.md`. Grading and the substitution
   classification stay valid; byte-level comparative claims do not.

This file remains useful either way: it pins *future* pairs, which is what
stops this from recurring.

The grader is unaffected by which lock is used. It reads
`InstalledVersions::getReference('phpstan/phpstan')` at runtime rather than
hardcoding a version, so it grades identically against any resolved PHPStan.
