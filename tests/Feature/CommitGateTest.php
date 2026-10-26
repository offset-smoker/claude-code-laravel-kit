<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;
use Symfony\Component\Process\Process;

const BAD_STYLE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return   'Hello, '.\$name;\n    }\n}\n";

function payload(string $command, string $cwd, string $tool = 'Bash'): string
{
    return (string) json_encode([
        'session_id' => 'test-session',
        'transcript_path' => '/dev/null',
        'cwd' => $cwd,
        'permission_mode' => 'default',
        'hook_event_name' => 'PreToolUse',
        'tool_name' => $tool,
        'tool_input' => ['command' => $command, 'description' => 'Run a command'],
        'tool_use_id' => 'toolu_test',
    ]);
}

function hook(FixtureRepository $repository, string $command, ?string $cwd = null, array $environment = []): Process
{
    /** @var array<string, string> $environment */
    return $repository->runScript('commit-gate.sh', [], payload($command, $cwd ?? $repository->path), $environment);
}

it('lets a commit through when the fast gate passes', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('README.md', "# Fixture\n");
    $repository->stage('README.md');

    $process = hook($repository, 'git commit -m "Add readme"');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('');
});

it('blocks a commit when the fast gate fails, with the reason and a bypass note', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);
    $repository->stage('app/Greeter.php');

    $process = hook($repository, 'git commit -m "Change greeting"');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('laravel-kit: commit blocked')
        ->toContain('FAIL pint --test')
        ->toContain('app/Greeter.php')
        ->toContain('Do not bypass this gate on your own')
        ->toContain('LARAVEL_KIT_FAST_GATE=off')
        ->toContain('FAST_GATE=off');
});

it('does not run the commit itself', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);
    $repository->stage('app/Greeter.php');
    $before = $repository->git('rev-parse', 'HEAD');

    hook($repository, 'git commit -m "Change greeting"');

    expect($repository->git('rev-parse', 'HEAD'))->toBe($before);
});

it('passes every command that is not a commit', function (string $command): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);
    $repository->stage('app/Greeter.php');

    $process = hook($repository, $command);

    expect($process->getExitCode())->toBe(0);
})->with([
    'status' => ['git status'],
    'push' => ['git push origin main'],
    'echo' => ['echo "git commit"'],
    'ls' => ['ls -la'],
]);

it('ignores tools other than Bash', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);
    $repository->stage('app/Greeter.php');

    $process = $repository->runScript('commit-gate.sh', [], payload('git commit -m x', $repository->path, 'Write'));

    expect($process->getExitCode())->toBe(0);
});

it('follows cd and git -C to the repository being committed', function (string $template): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);
    $repository->stage('app/Greeter.php');
    $elsewhere = FixtureRepository::plainDirectory();

    $process = hook($repository, sprintf($template, $repository->path), $elsewhere);

    expect($process->getExitCode())->toBe(2);
})->with([
    'cd' => ['cd %s && git commit -m x'],
    'cd --' => ['cd -- %s && git commit -m x'],
    'cd -P' => ['cd -P %s && git commit -m x'],
    'git -C' => ['git -C %s commit -m x'],
    'git --git-dir' => ['git --git-dir=%s/.git commit -m x'],
    'sudo' => ['cd %s && sudo -u nobody git commit -m x'],
]);

it('checks unstaged changes for commit -a', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);

    expect(hook($repository, 'git commit -m x')->getExitCode())->toBe(0)
        ->and(hook($repository, 'git commit -am x')->getExitCode())->toBe(2);
});

it('checks only the paths a commit names', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('routes/web.php', $repository->read('routes/web.php')."// More routes.\n");
    $repository->write('app/Greeter.php', BAD_STYLE);

    expect(hook($repository, 'git commit -m x routes/web.php')->getExitCode())->toBe(0)
        ->and(hook($repository, 'git commit -m x app/Greeter.php')->getExitCode())->toBe(2)
        ->and(hook($repository, 'git commit -m x Greeter.php', $repository->path.'/app')->getExitCode())->toBe(2)
        ->and(hook($repository, 'git commit -m x ../routes/web.php', $repository->path.'/app')->getExitCode())->toBe(0);
});

it('checks every unstaged change when it cannot tell which paths a commit names', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);

    expect(hook($repository, 'git commit -m x --pathspec-from-file=paths.txt')->getExitCode())->toBe(2)
        ->and(hook($repository, 'git commit -m x "$FILE"')->getExitCode())->toBe(2);
});

it('can be switched off for a session or per repository', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', BAD_STYLE);
    $repository->stage('app/Greeter.php');

    $session = hook($repository, 'git commit -m x', environment: ['LARAVEL_KIT_FAST_GATE' => 'off']);

    $repository->writeConfig(['FAST_GATE' => 'off']);
    $perRepository = hook($repository, 'git commit -m x');

    expect($session->getExitCode())->toBe(0)
        ->and($perRepository->getExitCode())->toBe(0);
});

it('blocks with a clear message when the configuration is invalid', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('.laravel-kit.conf', "FAST_GATE=sometimes\n");

    $process = hook($repository, 'git commit -m x');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('FAST_GATE must be on or off')
        ->toContain('.laravel-kit.conf is invalid');
});

it('stays out of repositories that are not PHP projects', function (): void {
    $repository = FixtureRepository::createEmpty();
    $repository->write('broken.php', BAD_STYLE);
    $repository->stage('broken.php');

    expect(hook($repository, 'git commit -m x')->getExitCode())->toBe(0);
});

it('stays out of directories that are not repositories', function (): void {
    $repository = FixtureRepository::create();

    expect(hook($repository, 'git commit -m x', FixtureRepository::plainDirectory())->getExitCode())->toBe(0);
});

it('passes a payload it cannot read', function (string $stdin): void {
    $repository = FixtureRepository::create();

    expect($repository->runScript('commit-gate.sh', [], $stdin)->getExitCode())->toBe(0);
})->with(['not json' => ['{oops'], 'empty' => ['']]);
