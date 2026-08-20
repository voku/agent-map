#!/usr/bin/env bash
# Reproduces the agent-map issue #25 navigation-cost replays end to end.
#
#   tools/dogfood/run-replays.sh /path/to/workspace
#
# Clones the frozen upstream repositories, checks out every replay's base commit into its own
# worktree, and runs each replay under both map backends. Reports land in <workspace>/reports/.
#
# The phpstan runs need phpstan/phpstan installed in this checkout (composer install --dev);
# without it agent-map builds structural-only maps and the phpstan rows cannot be produced.
set -euo pipefail

workspace="${1:?usage: run-replays.sh <workspace-dir>}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
mkdir -p "$workspace/reports"

clone() {
    local url="$1" dir="$2"
    [ -d "$dir/.git" ] || git clone "$url" "$dir"
}

worktree() {
    local repo="$1" commit="$2" path="$3"
    [ -d "$path" ] || git -C "$repo" worktree add "$path" "$commit"
}

clone https://github.com/voku/portable-ascii "$workspace/portable-ascii"
clone https://github.com/voku/Simple-PHP-Code-Parser "$workspace/simple-php-code-parser"

worktree "$workspace/portable-ascii" 88f94f89fe03bed03eb8fbcfb84178a8a5eb1d5b "$workspace/frozen/portable-ascii-135"
worktree "$workspace/portable-ascii" c5aede519cc55833267bcbb421b222d7aacfaa06 "$workspace/frozen/portable-ascii-62"
worktree "$workspace/simple-php-code-parser" 53f1b5085ee883560afa9326ee914f6b23acd6ae "$workspace/frozen/simple-php-code-parser-101"

for replay in portable-ascii-135 simple-php-code-parser-101 portable-ascii-62; do
    for backend in structural phpstan; do
        echo "== $replay ($backend)"
        php "$root/tools/dogfood/navigation-replay.php" \
            --replay="$root/tools/dogfood/replays/$replay.json" \
            --repo="$workspace/frozen/$replay" \
            --artifacts="$workspace/artifacts/$replay-$backend" \
            --backend="$backend" \
            --json="$workspace/reports/$replay-$backend.json" > /dev/null
    done
done

php "$root/tools/dogfood/summarize-replays.php" --reports="$workspace/reports"
