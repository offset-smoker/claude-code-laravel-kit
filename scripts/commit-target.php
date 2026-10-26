<?php

declare(strict_types=1);

/*
 * Reads a PreToolUse payload on stdin. When the Bash command runs
 * `git commit`, prints the directory it runs in, then on a second line which
 * files to check (staged, all, only or include; see CommitCommand::mode()),
 * then one line per pathspec the commit names. Prints nothing otherwise.
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

fwrite(STDOUT, implode("\n", [$commit->directory, $commit->mode(), ...$commit->pathspecs])."\n");
