# Conventional arm validation evidence recovered from the Codex transcript

This file preserves the final validation report emitted by the Conventional arm. It is not claimed to be a byte-for-byte capture of the raw `composer ci` stdout stream.

- `composer validate --strict` — exit 0; `./composer.json is valid`.
- `php vendor/bin/phpunit` — exit 0; 236 tests, 2026 assertions, 1 skipped.
- `php vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=1G --no-progress` — exit 0; no errors.
- `composer ci` — exit 0; PHPUnit and PHPStan passed.
- `git diff --check` — exit 0.
- `git status --short` — candidate contained only `src/Index/AnalysisFingerprint.php` and `tests/AnalysisFingerprintTest.php` before commit.
- `git diff --stat` — 2 files changed, 65 insertions, 2 deletions.
- Generated `composer.lock` was removed before candidate diff generation.
- Candidate was committed as `0d7bbd8` (`Add PHPStan package reference to fingerprints`) after candidate evidence generation because the environment's higher-priority repository workflow required a commit.
- `gh auth status` reported no authenticated GitHub host; no PR was created from the Codex environment.

The transcript also contains successful PHPUnit and PHPStan console output, but this filing intentionally distinguishes observed validation results from a raw stdout artifact that was not exported separately.
