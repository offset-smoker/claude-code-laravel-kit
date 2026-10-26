# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Please report security problems privately by email to b@wagner.codes, not in
a public issue. Include the version, what you found, and the steps to
reproduce it. You will get a reply within a week.

Issues of particular interest for this plugin:

- a way to make the commit hook or a gate script run a command other than the
  configured tools (the scripts never source `.laravel-kit.conf` or pass any
  value through `eval` or a shell);
- the review scanner printing a secret it found;
- a hook payload that makes the hook block or allow the wrong command.
