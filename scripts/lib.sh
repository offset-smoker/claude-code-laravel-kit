# shellcheck shell=bash
#
# Shared helpers for the laravel-kit gate scripts. Written for bash 3.2, the
# version macOS ships, so no associative arrays, mapfile or ${var,,}.

LK_STEPS="pint phpstan tests boot git"
LK_FAST_STEPS="pint phpstan"

lk_trim() {
    local value=$1
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

# lk_in_list ITEM LIST: true when ITEM is in the comma- or space-separated LIST.
lk_in_list() {
    local item=$1 list
    list=" $(printf '%s' "$2" | tr ',' ' ') "
    case "$list" in
        *" $item "*) return 0 ;;
    esac
    return 1
}

lk_config_error() {
    printf 'laravel-kit: %s\n' "$1" >&2
    return 2
}

# lk_check_list KEY VALUE ALLOWED: every entry of VALUE must be in ALLOWED.
lk_check_list() {
    local entry
    for entry in $(printf '%s' "$2" | tr ',' ' '); do
        if ! lk_in_list "$entry" "$3"; then
            lk_config_error "$1 has '$entry'; allowed values are: $3"
            return 2
        fi
    done
}

# lk_load_config ROOT: sets the LK_* settings from defaults and ROOT/.laravel-kit.conf.
# The file is parsed, never sourced: it is KEY=value lines and # comments.
# shellcheck disable=SC2034 # the LK_* settings are read by the scripts that source this file
lk_load_config() {
    local root=$1
    local file="$root/.laravel-kit.conf"
    local line key value number=0

    LK_REMOTE=origin
    LK_PROTECTED_BRANCH=
    LK_PINT=vendor/bin/pint
    LK_PHPSTAN=vendor/bin/phpstan
    LK_PHPSTAN_MEMORY_LIMIT=1G
    LK_TEST_COMMAND=
    LK_SKIP=
    LK_FAST_GATE=on
    LK_FAST_GATE_SKIP=
    LK_FETCH=on

    if [ -f "$file" ]; then
        while IFS= read -r line || [ -n "$line" ]; do
            number=$((number + 1))
            line=$(lk_trim "${line%$'\r'}")

            case "$line" in
                '' | '#'*) continue ;;
                *=*) ;;
                *)
                    lk_config_error "$file:$number: expected KEY=value"
                    return 2
                    ;;
            esac

            key=$(lk_trim "${line%%=*}")
            value=$(lk_trim "${line#*=}")

            case "$value" in
                \"*\") value=${value#\"} value=${value%\"} ;;
                \'*\') value=${value#\'} value=${value%\'} ;;
            esac

            case "$key" in
                PROTECTED_BRANCH | REMOTE | PINT | PHPSTAN | PHPSTAN_MEMORY_LIMIT | TEST_COMMAND | SKIP | FAST_GATE | FAST_GATE_SKIP | FETCH)
                    printf -v "LK_$key" '%s' "$value"
                    ;;
                *)
                    lk_config_error "$file:$number: unknown key '$key'"
                    return 2
                    ;;
            esac
        done <"$file"
    fi

    case "$LK_FAST_GATE" in on | off) ;; *) lk_config_error "FAST_GATE must be on or off"; return 2 ;; esac
    case "$LK_FETCH" in on | off) ;; *) lk_config_error "FETCH must be on or off"; return 2 ;; esac
    lk_check_list SKIP "$LK_SKIP" "$LK_STEPS" || return 2
    lk_check_list FAST_GATE_SKIP "$LK_FAST_GATE_SKIP" "$LK_FAST_STEPS" || return 2

    if [ -z "$LK_REMOTE" ] || ! printf '%s' "$LK_REMOTE" | grep -Eq '^[A-Za-z0-9._-]+$'; then
        lk_config_error "REMOTE '$LK_REMOTE' is not a valid remote name"
        return 2
    fi

    if [ -n "$LK_PROTECTED_BRANCH" ] && ! git check-ref-format --branch "$LK_PROTECTED_BRANCH" >/dev/null 2>&1; then
        lk_config_error "PROTECTED_BRANCH '$LK_PROTECTED_BRANCH' is not a valid branch name"
        return 2
    fi

    if [ -z "$LK_PHPSTAN_MEMORY_LIMIT" ] || ! printf '%s' "$LK_PHPSTAN_MEMORY_LIMIT" | grep -Eq '^(-1|[0-9]+[KMG]?)$'; then
        lk_config_error "PHPSTAN_MEMORY_LIMIT '$LK_PHPSTAN_MEMORY_LIMIT' must look like 512M, 2G or -1"
        return 2
    fi

    return 0
}

# lk_default_test_command: the suite command when TEST_COMMAND is not set.
lk_default_test_command() {
    if [ -n "$LK_TEST_COMMAND" ]; then
        printf '%s' "$LK_TEST_COMMAND"
    elif [ -f artisan ]; then
        printf 'php artisan test'
    elif [ -x vendor/bin/pest ]; then
        printf 'vendor/bin/pest'
    elif [ -x vendor/bin/phpunit ]; then
        printf 'vendor/bin/phpunit'
    fi
}

# lk_protected_branch: PROTECTED_BRANCH, else the remote's default branch, else main.
lk_protected_branch() {
    local head
    if [ -n "$LK_PROTECTED_BRANCH" ]; then
        printf '%s' "$LK_PROTECTED_BRANCH"
        return
    fi
    head=$(git symbolic-ref --quiet --short "refs/remotes/$LK_REMOTE/HEAD" 2>/dev/null)
    if [ -n "$head" ]; then
        printf '%s' "${head#"$LK_REMOTE"/}"
        return
    fi
    printf 'main'
}

# lk_tool_available "COMMAND STRING": true when its first word can be run.
lk_tool_available() {
    local words
    read -r -a words <<<"$1"
    [ "${#words[@]}" -gt 0 ] || return 1
    case "${words[0]}" in
        */*) [ -x "${words[0]}" ] ;;
        *) command -v "${words[0]}" >/dev/null 2>&1 ;;
    esac
}

# lk_run "COMMAND STRING" [ARG...]: runs the command, split on whitespace, with
# the extra arguments appended. Nothing is passed through eval or a shell.
lk_run() {
    local words
    read -r -a words <<<"$1"
    shift
    "${words[@]}" "$@"
}
