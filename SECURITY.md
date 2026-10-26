# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Please report security problems privately by email to b@wagner.codes, not in
a public issue. Include the version, what you found, and the steps to
reproduce it. You will get a reply within a week.

## Trust model

The commands the gates run belong to the repository being checked. `PINT`,
`PHPSTAN` and `TEST_COMMAND` come from that repository's own
`.laravel-kit.conf`, and the defaults are its own `vendor/bin` tools and
`artisan`. The commit hook runs them automatically, as a `PreToolUse` hook,
the moment Claude attempts `git commit`, before any permission prompt. So a
repository you open with the plugin enabled chooses what runs on your
machine, much as its Composer scripts or test suite would.

This is by design and is not treated as a vulnerability. Enable the plugin
only for repositories you trust: install it with `--scope project` in those
repositories rather than at user scope, which applies it to every
repository you open, and review changes to `.laravel-kit.conf` the way you
review CI configuration.

## In scope

- a way to make a gate script run something other than the commands in
  `.laravel-kit.conf` and the documented defaults, for example by injecting
  a command through a hook payload, a file name, a branch name or a
  configuration value (the scripts never source `.laravel-kit.conf` and never
  pass a value through `eval` or a shell);
- the review scanner printing a secret it found;
- a hook payload that makes the hook block or allow the wrong command.
