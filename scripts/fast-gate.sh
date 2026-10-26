#!/usr/bin/env bash
#
# The fast gate: Pint (--test) and PHPStan on the PHP files a commit touches.
#
# Usage: fast-gate.sh [--include-unstaged] [directory]
#   --include-unstaged  also check tracked files changed in the working tree,
#                       for `git commit -a` and commits that name paths.
# Exit codes: 0 passed or nothing to check, 1 a check failed, 2 usage or
# configuration error.

set -u -o pipefail

KIT_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
# shellcheck source=scripts/lib.sh
. "$KIT_ROOT/scripts/lib.sh"

include_unstaged=0
directory=.

while [ $# -gt 0 ]; do
    case "$1" in
        --include-unstaged) include_unstaged=1 ;;
        -h | --help)
            sed -n '3,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        -*)
            printf 'fast-gate: unknown option %s\n' "$1" >&2
            exit 2
            ;;
        *) directory=$1 ;;
    esac
    shift
done

if ! root=$(git -C "$directory" rev-parse --show-toplevel 2>/dev/null); then
    printf 'fast-gate: %s is not inside a git repository\n' "$directory" >&2
    exit 2
fi
cd "$root" || exit 2
lk_load_config "$root" || exit 2

files=()
add_files() {
    local path
    while IFS= read -r -d '' path; do
        case "$path" in
            *.php) ;;
            *) continue ;;
        esac
        [ -f "$path" ] || continue
        if [ "${#files[@]}" -gt 0 ] && printf '%s\n' "${files[@]}" | grep -Fxq -- "$path"; then
            continue
        fi
        files+=("$path")
    done
}

add_files < <(git diff --cached --name-only -z --diff-filter=ACMR --no-renames)
if [ "$include_unstaged" -eq 1 ]; then
    add_files < <(git diff --name-only -z --diff-filter=ACMR --no-renames)
fi

if [ "${#files[@]}" -eq 0 ]; then
    printf 'laravel-kit fast gate: no PHP files in this commit, nothing to check.\n'
    exit 0
fi

printf 'laravel-kit fast gate: checking %d PHP file(s).\n' "${#files[@]}"
status=0

if lk_in_list pint "$LK_FAST_GATE_SKIP"; then
    printf 'SKIP pint (FAST_GATE_SKIP)\n'
elif ! lk_tool_available "$LK_PINT"; then
    printf 'SKIP pint: %s not found (run composer install, or set PINT in .laravel-kit.conf)\n' "$LK_PINT"
elif output=$(lk_run "$LK_PINT" --test "${files[@]}" 2>&1); then
    printf 'PASS pint --test\n'
else
    status=1
    printf 'FAIL pint --test: these files are not formatted. Run: %s %s\n' "$LK_PINT" "${files[*]}"
    printf '%s\n' "$output" | tail -n 40
fi

if lk_in_list phpstan "$LK_FAST_GATE_SKIP"; then
    printf 'SKIP phpstan (FAST_GATE_SKIP)\n'
elif ! lk_tool_available "$LK_PHPSTAN"; then
    printf 'SKIP phpstan: %s not found (run composer install, or set PHPSTAN in .laravel-kit.conf)\n' "$LK_PHPSTAN"
elif output=$(lk_run "$LK_PHPSTAN" analyse --no-progress --error-format=raw "--memory-limit=$LK_PHPSTAN_MEMORY_LIMIT" "${files[@]}" 2>&1); then
    printf 'PASS phpstan\n'
elif printf '%s' "$output" | grep -q 'No files found to analyse'; then
    printf 'PASS phpstan (the changed files are outside its configured paths)\n'
else
    status=1
    printf 'FAIL phpstan:\n'
    printf '%s\n' "$output" | tail -n 40
fi

exit "$status"
