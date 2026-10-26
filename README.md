# laravel-kit for Claude Code

A Claude Code plugin that holds AI-written Laravel changes to the same gates a
careful reviewer would. A coding agent writes code fast and reports success
confidently, and a team adopting one finds the gaps the same way every time:
a commit that fails Pint in CI, a Livewire action anyone can call, a
`$request->all()` straight into `create()`, an `env()` call that goes null
once config is cached, a "tests pass" with a suite that was never run. This
kit moves those checks to the moment the agent acts. A hook stops `git
commit` when Pint or PHPStan fails on the files being committed, a `review`
skill reviews the staged change against a Laravel security checklist, and a
`deploy-check` skill runs the full gate and answers PUSH or DO NOT PUSH. Each
check is a plain script, so it gives the same answer whether Claude, a
teammate or CI runs it.

## Requirements

- Claude Code with plugin support (the hook uses the `if` field, so a
  recent release)
- macOS or Linux, with bash (3.2 or later) and git
- PHP 8.3 or later on `PATH`
- In each repository: Laravel Pint and PHPStan (or Larastan) installed with
  Composer. The deploy check also expects an `artisan` file and a test suite.

## Install

From inside Claude Code:

```text
/plugin marketplace add offset-smoker/claude-code-laravel-kit
/plugin install laravel-kit@offset-smoker
```

Or from your shell:

```bash
claude plugin marketplace add offset-smoker/claude-code-laravel-kit
claude plugin install laravel-kit@offset-smoker
```

To roll it out to a team, run these once from the root of the Laravel
repository and commit the `.claude/settings.json` they write. Claude Code
then registers the marketplace for each teammate who trusts the folder.

```bash
claude plugin marketplace add offset-smoker/claude-code-laravel-kit --scope project
claude plugin install laravel-kit@offset-smoker --scope project
```

## What it adds

### The commit hook

A `PreToolUse` hook on the Bash tool (`hooks/hooks.json`). When Claude runs a
command containing `git commit`, `scripts/commit-gate.sh` works out which
repository the commit is for (following `cd`, `pushd` and `git -C`) and runs
the fast gate there:

- `vendor/bin/pint --test` on the PHP files in the commit
- `vendor/bin/phpstan analyse` on the same files, with your `phpstan.neon`

The files are the staged ones, plus unstaged tracked changes when the commit
uses `-a`, `--include`, `--only` or names paths. When a check fails, the hook
exits 2, Claude Code cancels the commit, and Claude receives the tool output
with this note:

```text
Fix the problems above, stage the fixes, and commit again. Do not bypass this gate on your own; ask the user if the failure looks wrong.
Bypass (a human decision): commit from your own terminal, which this hook does not see; or start Claude Code with LARAVEL_KIT_FAST_GATE=off; or set FAST_GATE=off (or FAST_GATE_SKIP=phpstan) in .laravel-kit.conf.
```

The hook stays out of the way everywhere else: it lets through every
command that is not a commit, repositories without a `composer.json`, and
directories outside git. If Pint or PHPStan is not installed, that check is
skipped (with a note in the gate output) rather than blocking every commit.
The hook times out after 300 seconds; Claude Code lets the commit run if it
does.

### `/laravel-kit:review`

Reviews what the next commit would record, that is the index, not the working
tree. Before Claude reads anything, the skill runs `scripts/review-scan.php`,
which checks only the lines the change adds:

| Rule | Flags |
|---|---|
| `mass-assignment` | `$guarded = []`, `Model::unguard()`, `create($request->all())` and the other write methods fed the whole request |
| `missing-authorization` | a new public controller or Livewire action in a file with no `authorize()`, `Gate::`, `can()`, `can:` middleware, form request or `abort_unless` |
| `unlocked-livewire-id` | a public Livewire property holding an id without `#[Locked]` |
| `raw-sql` | `DB::select`, `DB::statement`, `whereRaw` and friends with an interpolated or concatenated value |
| `env-outside-config` | `env()` anywhere but `config/` |
| `secret` | private keys, cloud and API tokens, literal passwords, and staged `.env` or key files (`id_rsa`, `oauth-private.key`, binary `.p12`/`.pfx` stores; a `.pem` only when it holds a private key). The value is never printed, and neither is any other finding's copy of that line |
| `debug-call` | `dd()`, `dump()`, `ray()`, `var_dump()` |
| `tests-not-touched` | code under `app/` changed with nothing under `tests/` |

Claude then reads the diff and works through the skill's checklist:
security (the rules above plus validation, scoping lookups to what the user
may see, and unescaped output), correctness (N+1 queries, transactions,
reversible migrations, retry-safe jobs, nulls) and tests. Scanner findings
are leads that Claude confirms or dismisses. The report opens with **Ready
to commit** or **Fix before committing** and groups findings as Blocking,
Should fix and Notes, each with `path:line` and the fix. The skill only
reviews; it changes nothing. Pass a focus as an argument, for example
`/laravel-kit:review authorization only`.

The scripts have no dependencies beyond PHP, bash and git, so they also run
on their own, in CI or a git hook. From a clone of this repository:

```bash
git clone https://github.com/offset-smoker/claude-code-laravel-kit.git ~/laravel-kit
cd /path/to/your/laravel/app
php ~/laravel-kit/scripts/review-scan.php
php ~/laravel-kit/scripts/review-scan.php --format=json
```

The scanner exits 0 with no findings, 1 with findings, and 2 when the directory is
not a git repository.

### `/laravel-kit:deploy-check`

Runs `scripts/deploy-check.sh` and reports its verdict. Every step runs, so
one report shows every problem:

| Step | Runs | Fails when |
|---|---|---|
| `pint` | `vendor/bin/pint --test` | any file is not formatted |
| `phpstan` | `phpstan clear-result-cache`, then `phpstan analyse` | analysis reports an error. The cache is cleared first because a stale result cache can hide errors |
| `tests` | `php artisan test` (or `TEST_COMMAND`) | the suite fails |
| `boot` | `artisan config:cache`, `route:cache`, `view:cache`, then always `optimize:clear` | any cache command fails. This catches config, route and Blade errors the suite never executes |
| `git` | `git fetch`, then `git rev-list --left-right --count HEAD...origin/main` | the tree has uncommitted or untracked files, HEAD is behind the protected branch, or there is nothing to push |

The last line is `VERDICT: PUSH` (exit 0) or `VERDICT: DO NOT PUSH` (exit 1);
exit 2 means a usage or configuration error. A missing tool fails its step
rather than skipping it, because a deploy gate that quietly skips proves
nothing. The skill tells Claude not to push, not to run other suites at the
same time, and not to edit the configuration to get a pass. With the clone
from above, you can run the same check yourself:

```bash
~/laravel-kit/scripts/deploy-check.sh /path/to/your/laravel/app
```

Note that the `boot` step finishes with `php artisan optimize:clear`, which
also clears any caches you had built locally.

## Customize the gates per repository

Commit a `.laravel-kit.conf` at the repository root.
[`examples/laravel-kit.conf`](examples/laravel-kit.conf) lists every key
with its default. For example, a repository that deploys from `production`,
runs its suite in parallel and has not adopted PHPStan yet:

```ini
PROTECTED_BRANCH=production
TEST_COMMAND=php artisan test --parallel
SKIP=phpstan
FAST_GATE_SKIP=phpstan
```

| Key | Default | Meaning |
|---|---|---|
| `PROTECTED_BRANCH` | the remote's default branch, else `main` | branch the deploy check compares against |
| `REMOTE` | `origin` | remote holding that branch |
| `FETCH` | `on` | fetch before comparing; `off` uses the last fetch |
| `PINT` | `vendor/bin/pint` | Pint command |
| `PHPSTAN` | `vendor/bin/phpstan` | PHPStan or Larastan command |
| `PHPSTAN_MEMORY_LIMIT` | `1G` | passed as `--memory-limit` |
| `TEST_COMMAND` | `php artisan test`, else `vendor/bin/pest`, else `vendor/bin/phpunit` | the full suite |
| `SKIP` | none | deploy-check steps to skip: `pint`, `phpstan`, `tests`, `boot`, `git` |
| `FAST_GATE` | `on` | `off` turns the commit hook off in this repository |
| `FAST_GATE_SKIP` | none | fast-gate checks to skip: `pint`, `phpstan` |

The file is read as `KEY=value` lines and `#` comments; it is never sourced
or evaluated. An unknown key or a bad value stops the scripts with exit 2
and the line number, so a typo cannot quietly turn a gate off. Skipped steps
are printed as `SKIP` and named again beside the verdict. Commands are split
on spaces, without shell quoting, so a path in a command must not contain a
space.

`.laravel-kit.conf` decides which commands run on every commit and push, so
review changes to it the way you review CI configuration. The same goes for
the repository's own `vendor/bin` tools, which the hook runs: only enable the
plugin in repositories you trust.

To change the review checklist or the hook itself, fork this repository and
edit `skills/review/SKILL.md` or `hooks/hooks.json`; then point
`/plugin marketplace add` at your fork.

## Testing

```bash
composer install
vendor/bin/pest                    # unit, feature and arch tests
vendor/bin/phpstan analyse         # level max, strict rules, no baseline
vendor/bin/pint --test
shellcheck -x scripts/*.sh
claude plugin validate .
```

The feature tests run the real scripts. Each test copies
`tests/Fixtures/app`, a small Laravel-shaped project with a stand-in
`artisan`, into a temporary git repository with a bare `origin` beside it,
then asserts exit codes and output for passing and failing cases: unformatted
code, PHPStan errors, a failing suite, a route that will not cache, a dirty
tree, a branch that is behind, and an invalid configuration. Pint and PHPStan
are the real tools from this repository's `vendor/`. Git runs with no global
or system configuration, so your own git settings cannot change a result. CI
runs the suite on PHP 8.3 and 8.4, on Ubuntu and on macOS (bash 3.2 and BSD
tools), plus ShellCheck and `claude plugin validate`.

## Status and limitations

Version 0.1.0. What it does not do:

- **The hook is a guard rail, not a lock.** It sees only commands Claude runs
  through the Bash tool. It finds commits by parsing the command line, so a
  commit hidden in a script, an alias or an `eval` is not seen, and `cd`
  into a path built from a variable is not followed (the hook then checks
  the current directory). For a hard rule, also run the fast gate in a git
  pre-commit hook or in CI.
- **The fast gate checks working-tree files.** Pint and PHPStan read files
  from disk, so if a file is only partly staged, they check the whole file,
  not just the staged part.
- **The scanner is pattern matching on added lines.** It misses issues
  spread over several lines and reports some false positives;
  `missing-authorization` cannot see policies applied in route files or
  service providers. The review skill treats its findings as leads for
  Claude to confirm.
- **The deploy check runs the suite as configured.** It cannot tell whether
  the suite covers the change, and the `boot` step needs a bootable
  application (a `.env` and whatever services config caching touches).
- No Windows support. The scripts need bash and POSIX tools.

## How this was built

Written with Claude Code under Brandon Wagner's direction and review.

## License

MIT. See [LICENSE](LICENSE).
