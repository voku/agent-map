# Security policy

## Reporting a vulnerability

Please open a private security advisory on GitHub or contact the maintainer
directly at lars@moelleken.org rather than filing a public issue if the
report includes vulnerability or exploit details.

## What this package does to stay safe by default

- **Safe Parsing and Analysis**: Symbol parsing operates via read-only AST
  extraction (`nikic/php-parser` via `voku/simple-php-code-parser`) and optional
  PHPStan reflection without executing scanned code.
- **Index Sandboxing**: Map indices and caches are kept strictly within
  configured repository boundaries.
- **Contextual Errors**: Exceptions are typed and structured, avoiding
  accidental information disclosure.

## Supported versions

This project is pre-1.0; only the latest commit on the default branch
receives security fixes until a 1.0.0 stability policy is published.
