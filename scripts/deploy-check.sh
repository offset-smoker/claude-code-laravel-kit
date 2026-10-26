#!/usr/bin/env bash
#
# The pre-push gate. Runs every step, then prints one verdict:
#   pint     vendor/bin/pint --test
#   phpstan  phpstan clear-result-cache, then phpstan analyse
#   tests    the full suite (TEST_COMMAND, default php artisan test)
#   boot     artisan config:cache, route:cache, view:cache, then optimize:clear
#   git      clean tree, and HEAD strictly ahead of REMOTE/PROTECTED_BRANCH
#
# Usage: deploy-check.sh [directory]
# Exit codes: 0 VERDICT: PUSH, 1 VERDICT: DO NOT PUSH, 2 usage or
# configuration error.

set -u -o pipefail

KIT_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
# shellcheck source=scripts/lib.sh
. "$KIT_ROOT/scripts/lib.sh"

directory=.
case "${1:-}" in
    -h | --help)
        sed -n '3,13p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
        exit 0
        ;;
    -*)
        printf 'deploy-check: unknown option %s\n' "$1" >&2
        exit 2
        ;;
    '') ;;
    *) directory=$1 ;;
esac

if ! root=$(git -C "$directory" rev-parse --show-toplevel 2>/dev/null); then
    printf 'deploy-check: %s is not inside a git repository\n' "$directory" >&2
    exit 2
fi
cd "$root" || exit 2
lk_load_config "$root" || exit 2

log=$(mktemp "${TMPDIR:-/tmp}/laravel-kit-deploy-check.XXXXXX")
trap 'rm -f "$log"' EXIT

failed=0
skipped=

report() {
    # report STATUS STEP DETAIL
    printf '[%s] %-8s %s\n' "$1" "$2" "$3"
}

show_log() {
    if [ -s "$log" ]; then
        # awk, unlike BSD sed, ends the last line even when the log does not.
        tail -n 30 "$log" | awk '{ print "           | " $0 }'
    fi
}

fail() {
    failed=1
    report FAIL "$1" "$2"
    show_log
}

skip_requested() {
    if lk_in_list "$1" "$LK_SKIP"; then
        skipped="$skipped $1"
        report SKIP "$1" 'skipped by SKIP in .laravel-kit.conf'
        return 0
    fi
    return 1
}

require_tool() {
    # require_tool STEP "COMMAND STRING"
    if lk_tool_available "$2"; then
        return 0
    fi
    : >"$log"
    fail "$1" "$2 not found. Run composer install, set the command in .laravel-kit.conf, or add $1 to SKIP."
    return 1
}

step_pint() {
    skip_requested pint && return
    require_tool pint "$LK_PINT" || return
    if lk_run "$LK_PINT" --test >"$log" 2>&1; then
        report PASS pint "$LK_PINT --test"
    else
        fail pint "$LK_PINT --test found files that are not formatted. Run $LK_PINT, review, commit."
    fi
}

step_phpstan() {
    skip_requested phpstan && return
    require_tool phpstan "$LK_PHPSTAN" || return
    if ! lk_run "$LK_PHPSTAN" clear-result-cache >"$log" 2>&1; then
        fail phpstan "$LK_PHPSTAN clear-result-cache failed"
        return
    fi
    if lk_run "$LK_PHPSTAN" analyse --no-progress "--memory-limit=$LK_PHPSTAN_MEMORY_LIMIT" >"$log" 2>&1; then
        report PASS phpstan "analyse from a cleared result cache"
    else
        fail phpstan "phpstan analyse reported errors (result cache was cleared first)"
    fi
}

step_tests() {
    local command
    skip_requested tests && return
    command=$(lk_default_test_command)
    if [ -z "$command" ]; then
        : >"$log"
        fail tests 'no test command found. Set TEST_COMMAND in .laravel-kit.conf.'
        return
    fi
    require_tool tests "$command" || return
    if lk_run "$command" >"$log" 2>&1; then
        report PASS tests "$command"
    else
        fail tests "$command failed"
    fi
}

step_boot() {
    local subcommand broke=
    skip_requested boot && return
    if [ ! -f artisan ]; then
        : >"$log"
        fail boot 'no artisan file here. Add boot to SKIP if this is not a Laravel application.'
        return
    fi
    : >"$log"
    for subcommand in config:cache route:cache view:cache; do
        if ! php artisan "$subcommand" >>"$log" 2>&1; then
            broke=$subcommand
            break
        fi
    done
    if ! php artisan optimize:clear >>"$log" 2>&1; then
        fail boot 'php artisan optimize:clear failed, so cached config, routes or views may be left behind. Clear them before you work on.'
        return
    fi
    if [ -n "$broke" ]; then
        fail boot "php artisan $broke failed (the caches were cleared again afterwards)"
        return
    fi
    report PASS boot 'config:cache, route:cache, view:cache, then optimize:clear'
}

step_git() {
    local branch upstream counts ahead behind dirty
    skip_requested git && return
    : >"$log"
    branch=$(lk_protected_branch)
    upstream="$LK_REMOTE/$branch"

    dirty=$(git status --porcelain --untracked-files=normal)
    if [ -n "$dirty" ]; then
        printf '%s\n' "$dirty" >"$log"
        fail git 'the working tree has uncommitted or untracked files, so the checks above did not run on what you would push'
        return
    fi

    if [ "$LK_FETCH" = on ] && ! git fetch --quiet "$LK_REMOTE" "$branch" >"$log" 2>&1; then
        fail git "git fetch $LK_REMOTE $branch failed, so ahead/behind cannot be trusted. Set FETCH=off to compare against the last fetch."
        return
    fi

    if ! git rev-parse --verify --quiet "refs/remotes/$upstream" >/dev/null; then
        fail git "$upstream does not exist. Set REMOTE and PROTECTED_BRANCH in .laravel-kit.conf."
        return
    fi

    counts=$(git rev-list --left-right --count "HEAD...refs/remotes/$upstream")
    ahead=$(printf '%s' "$counts" | awk '{print $1}')
    behind=$(printf '%s' "$counts" | awk '{print $2}')

    if [ "$behind" -gt 0 ]; then
        fail git "HEAD is $behind commit(s) behind $upstream (and $ahead ahead). Rebase onto $upstream and run the checks again."
        return
    fi

    if [ "$ahead" -eq 0 ]; then
        fail git "HEAD has no commits that $upstream does not already have. Nothing to push."
        return
    fi

    report PASS git "clean tree, $ahead commit(s) ahead of $upstream, 0 behind"
}

printf 'laravel-kit deploy check in %s\n\n' "$root"

step_pint
step_phpstan
step_tests
step_boot
step_git

printf '\n'
if [ -n "$skipped" ]; then
    printf 'Skipped by configuration:%s\n' "$skipped"
fi

if [ "$failed" -ne 0 ]; then
    printf 'VERDICT: DO NOT PUSH\n'
    exit 1
fi

printf 'VERDICT: PUSH\n'
exit 0
