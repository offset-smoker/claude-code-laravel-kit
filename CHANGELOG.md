# Changelog

## 0.1.0 (2026-10-26)

First release.

- `review` skill: reviews the staged change for Laravel security problems,
  correctness and missing tests, seeded by a mechanical scan of the added
  lines (`scripts/review-scan.php`).
- `deploy-check` skill: runs `scripts/deploy-check.sh` (Pint, PHPStan from a
  cleared result cache, the test suite, the config/route/view cache boot
  check with `optimize:clear`, and an ahead-only git check) and reports
  `VERDICT: PUSH` or `VERDICT: DO NOT PUSH`.
- PreToolUse hook on Bash: blocks `git commit` when Pint `--test` or PHPStan
  fails on the files in the commit.
- Per-repository settings in `.laravel-kit.conf`.
- Marketplace manifest for `/plugin marketplace add offset-smoker/claude-code-laravel-kit`.
