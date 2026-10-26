<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Hook\CommitDetector;

dataset('commits', [
    'plain' => ['git commit -m "Add greeter"', '/repo', false],
    'after add' => ['git add app/Foo.php && git commit -m x', '/repo', false],
    'cd first' => ['cd packages/api && git commit -m x', '/repo/packages/api', false],
    'cd absolute' => ['cd /elsewhere; git commit', '/elsewhere', false],
    'cd home' => ['cd ~/code/app && git commit', '/home/dev/code/app', false],
    'git -C' => ['git -C sub commit -m x', '/repo/sub', false],
    'git -c then -C' => ['git -c core.editor=true -C /abs commit', '/abs', false],
    'work tree' => ['git --work-tree=/wt commit -m x', '/wt', false],
    'env prefix' => ['GIT_AUTHOR_DATE=now git commit -m x', '/repo', false],
    'wrapper' => ['env FOO=1 command git commit -m x', '/repo', false],
    'full path git' => ['/usr/bin/git commit -m x', '/repo', false],
    '-a' => ['git commit -a -m x', '/repo', true],
    '-am' => ['git commit -am "x"', '/repo', true],
    '--all' => ['git commit --all --message=x', '/repo', true],
    'pathspec' => ['git commit -m x app/Foo.php', '/repo', true],
    'pathspec after --' => ['git commit -m x -- app/Foo.php', '/repo', true],
    'bare --' => ['git commit -m x --', '/repo', false],
    'message that looks like -a' => ['git commit -m -a', '/repo', false],
    'attached message' => ['git commit -mfix', '/repo', false],
    'attached message ending in C' => ['git commit -mABC', '/repo', false],
    'reuse message' => ['git commit -C HEAD --amend', '/repo', false],
    'long option with value' => ['git commit --author "A <a@example.test>" -m x', '/repo', false],
    'redirection' => ['git commit -m x > /dev/null 2>&1', '/repo', false],
    'heredoc message' => ["git commit -m \"\$(cat <<'EOF'\nFix it\nEOF\n)\"", '/repo', false],
    'include flag' => ['git commit -i app/Foo.php -m x', '/repo', true],
    'cd --' => ['cd -- /abs && git commit', '/abs', false],
    'cd -P' => ['cd -P sub && git commit', '/repo/sub', false],
    'cd -L -P' => ['cd -L -P /abs; git commit', '/abs', false],
    'cd -- -dash' => ['cd -- -dash && git commit', '/repo/-dash', false],
    'cd -' => ['cd - && git commit', '/repo', false],
    'pushd -n' => ['pushd -n /abs && git commit', '/repo', false],
    'git-dir attached' => ['git --git-dir=/abs/.git commit -m x', '/abs', false],
    'git-dir separate' => ['git --git-dir /abs/.git commit -m x', '/abs', false],
    'git-dir relative' => ['git --git-dir=sub/.git commit', '/repo/sub', false],
    'git-dir after -C' => ['git -C /abs --git-dir=.git commit', '/abs', false],
    'git-dir with work tree' => ['git --work-tree=/wt --git-dir=/abs/.git commit', '/wt', false],
    'sudo' => ['sudo git commit -m x', '/repo', false],
    'sudo with user' => ['sudo -u deploy git commit -m x', '/repo', false],
    'sudo with --' => ['sudo -E -- git commit -m x', '/repo', false],
    'nice with value' => ['nice -n 10 git commit -m x', '/repo', false],
    'env with option' => ['env -i PATH=/usr/bin git commit -m x', '/repo', false],
    'time -p' => ['time -p git commit -m x', '/repo', false],
]);

it('finds the commit and where it runs', function (string $command, string $directory, bool $includesUnstaged): void {
    $commit = (new CommitDetector('/home/dev'))->detect($command, '/repo');

    expect($commit)->not->toBeNull()
        ->and($commit?->directory)->toBe($directory)
        ->and($commit?->includesUnstaged)->toBe($includesUnstaged);
})->with('commits');

it('ignores commands that do not commit', function (string $command): void {
    expect((new CommitDetector('/home/dev'))->detect($command, '/repo'))->toBeNull();
})->with([
    'status' => ['git status'],
    'log grep' => ['git log --grep commit'],
    'echo' => ['echo git commit'],
    'quoted' => ['printf "%s" "git commit"'],
    'comment' => ['ls # git commit'],
    'commit-tree' => ['git commit-tree HEAD^{tree}'],
    'not git' => ['gitx commit'],
    'empty' => [''],
]);

it('leaves the directory alone when cd uses a variable', function (): void {
    $commit = (new CommitDetector)->detect('cd "$PROJECT" && git commit -m x', '/repo');

    expect($commit?->directory)->toBe('/repo');
});

it('leaves ~ unresolved when no home directory is known', function (): void {
    $commit = (new CommitDetector)->detect('cd ~/app && git commit', '/repo');

    expect($commit?->directory)->toBe('/repo');
});
