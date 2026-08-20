#!/usr/bin/env bash
# Reproduces the agent-map issue #25 navigation-cost replays end to end.
#
#   tools/dogfood/run-replays.sh /path/to/workspace
#
# Clones the frozen upstream repositories, checks out every replay's base commit into its own
# worktree, and runs each replay under both map backends. Reports land in <workspace>/reports/.
#
# The phpstan runs need phpstan/phpstan installed in this checkout (composer install --dev). This
# script checks that up front for a useful error, but the replay itself is the correctness boundary:
# it refuses to publish a report whose effective map backend is not the one that was requested.
set -euo pipefail

workspace="${1:?usage: run-replays.sh <workspace-dir>}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
mkdir -p "$workspace/reports"

# agent-map's own availability rule, so this preflight cannot disagree with the build it precedes.
phpstan_available() {
    php -r 'require $argv[1]; exit(\voku\AgentMap\Build\PhpStanSemanticAnalyzer::isAvailable() ? 0 : 1);' "$root/vendor/autoload.php"
}

if ! phpstan_available; then
    echo "PHPStan-backed replay requested, but phpstan/phpstan is unavailable." >&2
    echo "Install development dependencies (composer install) before generating PHPStan evidence." >&2
    exit 1
fi

clone() {
    local url="$1" dir="$2"
    [ -d "$dir/.git" ] || git clone "$url" "$dir"
}

worktree() {
    local repo="$1" commit="$2" path="$3"
    [ -d "$path" ] || git -C "$repo" worktree add "$path" "$commit"
}

# Repository and base commit come from the fixture, so the frozen identity is stated once. The
# replay re-checks the checked-out HEAD anyway; this only keeps the two from drifting apart.
fixture_field() {
    php -r '$f = json_decode(file_get_contents($argv[1]), true); echo is_array($f) && is_string($f[$argv[2]] ?? null) ? $f[$argv[2]] : exit(1);' "$1" "$2"
}

for replay in portable-ascii-135 simple-php-code-parser-101 portable-ascii-62; do
    fixture="$root/tools/dogfood/replays/$replay.json"
    url="$(fixture_field "$fixture" repository)"
    commit="$(fixture_field "$fixture" base_commit)"
    checkout="$workspace/$(basename "$url")"

    clone "$url" "$checkout"
    worktree "$checkout" "$commit" "$workspace/frozen/$replay"

    for backend in structural phpstan; do
        echo "== $replay ($backend)"
        php "$root/tools/dogfood/navigation-replay.php" \
            --replay="$fixture" \
            --repo="$workspace/frozen/$replay" \
            --artifacts="$workspace/artifacts/$replay-$backend" \
            --backend="$backend" \
            --json="$workspace/reports/$replay-$backend.json" > /dev/null
    done
done

php "$root/tools/dogfood/summarize-replays.php" --reports="$workspace/reports"
