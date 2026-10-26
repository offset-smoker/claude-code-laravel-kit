<?php

declare(strict_types=1);

/*
 * Reads a PreToolUse payload on stdin. When the Bash command runs
 * `git commit`, prints the directory it runs in and, on a second line, 1 if
 * the commit also takes unstaged changes (else 0). Prints nothing otherwise.
 */

use OffsetSmoker\LaravelKit\Hook\CommitDetector;
use OffsetSmoker\LaravelKit\Hook\HookInput;

require __DIR__.'/../src/autoload.php';

$stdin = stream_get_contents(STDIN);
$input = HookInput::fromJson(is_string($stdin) ? $stdin : '');

if ($input === null) {
    exit(0);
}

$home = getenv('HOME');
$commit = (new CommitDetector(is_string($home) ? $home : ''))->detect($input->command, $input->cwd);

if ($commit === null) {
    exit(0);
}

fwrite(STDOUT, $commit->directory."\n".($commit->includesUnstaged ? '1' : '0')."\n");
