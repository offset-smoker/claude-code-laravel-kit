---
name: deploy-check
description: Pre-push gate for a Laravel repository. Runs Pint --test, PHPStan from a cleared result cache, the full test suite, the config/route/view cache boot check followed by optimize:clear, and an ahead-only git check against the protected branch, then reports PUSH or DO NOT PUSH. Use before any git push or when the user asks whether a branch is ready to push.
allowed-tools: Bash(${CLAUDE_PLUGIN_ROOT}/scripts/deploy-check.sh) Bash(${CLAUDE_PLUGIN_ROOT}/scripts/deploy-check.sh *)
---

# Deploy check

Run the gate from the repository root, in the foreground, with the longest
Bash timeout (600000 ms). If the suite is known to take longer than ten
minutes, run it with `run_in_background` and wait for it to finish.

```bash
${CLAUDE_PLUGIN_ROOT}/scripts/deploy-check.sh
```

Rules while it runs:

- Run it once, and do not run tests or other suites at the same time. Two
  suites against one database produce failures that are not real and hide
  ones that are.
- Do not push, even on PUSH. Pushing is the user's call.
- Do not change `.laravel-kit.conf`, skip steps, or edit tests to make the
  gate pass. If a step looks wrong for this repository, say so and let the
  user decide.

## Report

Quote the `VERDICT:` line exactly as the script printed it, first.

- **VERDICT: PUSH**: list the steps and their one-line results. Mention any
  step skipped by configuration, because a skipped step proves nothing.
- **VERDICT: DO NOT PUSH**: for each `[FAIL]` step, give the cause from its
  output and the fix. For `git` failures, say whether the branch needs a
  rebase, a commit of stray files, or simply has nothing to push. Offer to
  fix the code failures; do not start until the user agrees.

Exit code 2 means a usage or configuration error: show the message and the
line of `.laravel-kit.conf` it names.
