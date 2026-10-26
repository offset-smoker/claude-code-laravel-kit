#!/usr/bin/env bash
#
# PreToolUse hook for the Bash tool. When Claude runs `git commit`, it runs
# the fast gate in that repository and blocks the commit (exit 2) when the
# gate fails. Everything else passes through untouched (exit 0).
#
# The hook payload arrives as JSON on stdin; see the Claude Code hooks docs.

set -u -o pipefail

KIT_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
# shellcheck source=scripts/lib.sh
. "$KIT_ROOT/scripts/lib.sh"

payload=$(cat)

if [ "${LARAVEL_KIT_FAST_GATE:-on}" = off ]; then
    exit 0
fi

if ! command -v php >/dev/null 2>&1; then
    printf 'laravel-kit: php is not on PATH, so the commit gate did not run.\n' >&2
    exit 1
fi

if ! target=$(printf '%s' "$payload" | php "$KIT_ROOT/scripts/commit-target.php"); then
    printf 'laravel-kit: could not read the hook payload, so the commit gate did not run.\n' >&2
    exit 1
fi

if [ -z "$target" ]; then
    exit 0
fi

# commit-target.php prints the directory, the mode, then one pathspec per line.
directory=$(printf '%s\n' "$target" | sed -n 1p)
mode=$(printf '%s\n' "$target" | sed -n 2p)
pathspecs=()
while IFS= read -r pathspec; do
    pathspecs+=("$pathspec")
done < <(printf '%s\n' "$target" | sed -n '3,$p')

if ! root=$(git -C "$directory" rev-parse --show-toplevel 2>/dev/null); then
    exit 0
fi

if [ ! -f "$root/composer.json" ]; then
    exit 0
fi

if ! lk_load_config "$root"; then
    printf 'laravel-kit: commit blocked because %s/.laravel-kit.conf is invalid (see above). Fix the file, then commit again.\n' "$root" >&2
    exit 2
fi

if [ "$LK_FAST_GATE" = off ]; then
    exit 0
fi

case "$mode" in
    staged) gate_options=() ;;
    all) gate_options=(--include-unstaged) ;;
    include) gate_options=(--include-unstaged) ;;
    only) gate_options=(--include-unstaged --only) ;;
    *)
        printf 'laravel-kit: unexpected commit mode %s, so the commit gate did not run.\n' "$mode" >&2
        exit 1
        ;;
esac

# Bash 3.2 treats "${empty[@]}" as unset under set -u, hence the ${x+...} forms.
output=$("$KIT_ROOT/scripts/fast-gate.sh" ${gate_options[@]+"${gate_options[@]}"} "$directory" -- ${pathspecs[@]+"${pathspecs[@]}"} 2>&1)
gate_status=$?

if [ "$gate_status" -eq 0 ]; then
    exit 0
fi

{
    printf 'laravel-kit: commit blocked. The fast gate (pint --test, phpstan on the changed files) failed in %s.\n\n' "$root"
    printf '%s\n\n' "$output"
    printf 'Fix the problems above, stage the fixes, and commit again. Do not bypass this gate on your own; ask the user if the failure looks wrong.\n'
    printf 'Bypass (a human decision): commit from your own terminal, which this hook does not see; or start Claude Code with LARAVEL_KIT_FAST_GATE=off; or set FAST_GATE=off (or FAST_GATE_SKIP=phpstan) in .laravel-kit.conf.\n'
} >&2
exit 2
