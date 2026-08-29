#!/usr/bin/env bash
#
# Hidden grader runner for experiment nav-substitution-01.
#
#   ./grade.sh <candidate-checkout>
#
# The candidate checkout must be the arm's working tree at base
# b8ecad69c6514514b40869e0a643b19fc019ebcf plus that arm's candidate patch,
# with dependencies installed (vendor/ present, phpstan/phpstan among them).
#
# Emits: GRADE=1 (correct) or GRADE=0 (incorrect), plus the raw PHPUnit output.
#
# The grader is copied in at run time and removed afterwards, so the candidate
# tree is never mutated and neither arm can ever have seen it.
set -uo pipefail

GRADER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CAND="${1:?usage: grade.sh <candidate-checkout>}"
CAND="$(cd "$CAND" && pwd)"

if [ ! -f "$CAND/vendor/autoload.php" ]; then
  echo "FATAL: $CAND/vendor/autoload.php missing - install dependencies first." >&2
  exit 2
fi

TARGET="$CAND/tests/AnalysisFingerprintGraderTest.php"
if [ -e "$TARGET" ]; then
  echo "FATAL: $TARGET already exists - refusing to overwrite candidate-owned file." >&2
  exit 2
fi

cp "$GRADER_DIR/AnalysisFingerprintGraderTest.php" "$TARGET"
trap 'rm -f "$TARGET"' EXIT

php "$CAND/vendor/bin/phpunit" \
  --do-not-cache-result \
  --no-coverage \
  --filter AnalysisFingerprintGraderTest \
  "$TARGET"
status=$?

if [ "$status" -eq 0 ]; then
  echo "GRADE=1"
else
  echo "GRADE=0"
fi
exit 0
