<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Hook\ShellWords;

it('splits simple commands on control operators', function (): void {
    expect(ShellWords::commands('cd app && git add . ; git commit -m x || echo failed | tee log'))->toBe([
        ['cd', 'app'],
        ['git', 'add', '.'],
        ['git', 'commit', '-m', 'x'],
        ['echo', 'failed'],
        ['tee', 'log'],
    ]);
});

it('keeps quoted operators and spaces inside one word', function (): void {
    expect(ShellWords::commands('git commit -m "fix: a && b; c" -m \'it\'\'s "fine"\''))->toBe([
        ['git', 'commit', '-m', 'fix: a && b; c', '-m', 'its "fine"'],
    ]);
});

it('handles backslash escapes inside and outside double quotes', function (): void {
    expect(ShellWords::commands('echo a\ b "say \"hi\" \$HOME"'))->toBe([
        ['echo', 'a b', 'say "hi" $HOME'],
    ]);
});

it('treats newlines as separators and drops comments', function (): void {
    expect(ShellWords::commands("git status # git commit\ngit log"))->toBe([
        ['git', 'status'],
        ['git', 'log'],
    ]);
});

it('keeps an empty quoted word', function (): void {
    expect(ShellWords::commands('git commit -m ""'))->toBe([['git', 'commit', '-m', '']]);
});

it('returns nothing for blank input', function (): void {
    expect(ShellWords::commands("  \n ; "))->toBe([]);
});
