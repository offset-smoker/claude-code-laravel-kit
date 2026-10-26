<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;

/**
 * A fixture repository one commit ahead of origin/main, which is the shape
 * of a branch that is ready to push.
 *
 * @param  array<string, string>  $config
 */
function readyToPush(array $config = []): FixtureRepository
{
    $repository = FixtureRepository::create($config);
    $repository->write('app/Farewell.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Farewell\n{\n    public function say(): string\n    {\n        return 'Bye';\n    }\n}\n");
    $repository->stage('app/Farewell.php');
    $repository->commit('Add farewell');

    return $repository;
}

/**
 * @return array<string, string> step => status, as printed by the script
 */
function steps(string $output): array
{
    preg_match_all('/^\[(PASS|FAIL|SKIP)\] (\w+)/m', $output, $matches, PREG_SET_ORDER);

    $steps = [];

    foreach ($matches as $match) {
        $steps[$match[2]] = $match[1];
    }

    return $steps;
}

it('says PUSH when every step passes', function (): void {
    $repository = readyToPush();

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(0)
        ->and(steps($process->getOutput()))->toBe(['pint' => 'PASS', 'phpstan' => 'PASS', 'tests' => 'PASS', 'boot' => 'PASS', 'git' => 'PASS'])
        ->and($process->getOutput())->toContain('1 commit(s) ahead of origin/main, 0 behind')
        ->toEndWith("VERDICT: PUSH\n");
});

it('leaves no framework caches behind', function (): void {
    $repository = readyToPush();

    $repository->runScript('deploy-check.sh');

    expect(glob($repository->path.'/bootstrap/cache/*.php'))->toBe([]);
});

it('says DO NOT PUSH when Pint finds unformatted code', function (): void {
    $repository = readyToPush();
    $repository->write('app/Greeter.php', str_replace('return "Hello, {$name}";', 'return   "Hello, {$name}";', $repository->read('app/Greeter.php')));
    $repository->commit('Unformatted');

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and(steps($process->getOutput()))->toMatchArray(['pint' => 'FAIL', 'phpstan' => 'PASS', 'git' => 'PASS'])
        ->and($process->getOutput())->toEndWith("VERDICT: DO NOT PUSH\n");
});

it('says DO NOT PUSH when PHPStan reports an error', function (): void {
    $repository = readyToPush();
    $repository->write('app/Greeter.php', str_replace('): string', '): int', $repository->read('app/Greeter.php')));
    $repository->commit('Type error');

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and(steps($process->getOutput()))->toMatchArray(['phpstan' => 'FAIL'])
        ->and($process->getOutput())->toContain('should return int but returns string');
});

it('clears the PHPStan result cache before analysing', function (): void {
    $repository = readyToPush();
    $log = $repository->path.'/../phpstan-calls.log';
    $spy = $repository->path.'/../phpstan-spy';
    file_put_contents($spy, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\nexec ".escapeshellarg(FixtureRepository::kitPath('vendor/bin/phpstan'))." \"\$@\"\n");
    chmod($spy, 0755);
    $repository->writeConfig(['PINT' => FixtureRepository::kitPath('vendor/bin/pint'), 'PHPSTAN' => $spy]);
    $repository->commit('Use the spy');

    $process = $repository->runScript('deploy-check.sh');
    $calls = file($log, FILE_IGNORE_NEW_LINES) ?: [];

    expect($process->getExitCode())->toBe(0)
        ->and($calls)->toHaveCount(2)
        ->and($calls[0] ?? null)->toBe('clear-result-cache')
        ->and($calls[1] ?? '')->toStartWith('analyse --no-progress --memory-limit=1G');
});

it('says DO NOT PUSH when the test suite fails', function (): void {
    $repository = readyToPush();
    $repository->write('app/Greeter.php', str_replace('Hello, ', 'Hi, ', $repository->read('app/Greeter.php')));
    $repository->commit('Break the greeting');

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and(steps($process->getOutput()))->toMatchArray(['tests' => 'FAIL', 'pint' => 'PASS'])
        ->and($process->getOutput())->toContain("expected 'Hello, Ada', got 'Hi, Ada'");
});

it('uses TEST_COMMAND when it is set', function (): void {
    $repository = readyToPush(['TEST_COMMAND' => 'php tests/check.php']);

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getOutput())->toContain('[PASS] tests    php tests/check.php');
});

it('says DO NOT PUSH when a cache command fails, and still clears the caches', function (): void {
    $repository = readyToPush();
    $repository->write('routes/web.php', "<?php\n\ndeclare(strict_types=1);\n\n// BROKEN_ROUTE\n");
    $repository->commit('Break a route');

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and(steps($process->getOutput()))->toMatchArray(['boot' => 'FAIL'])
        ->and($process->getOutput())->toContain('php artisan route:cache failed (the caches were cleared again afterwards)')
        ->toContain('Unable to prepare route')
        ->and(glob($repository->path.'/bootstrap/cache/*.php'))->toBe([]);
});

it('needs an artisan file for the boot check', function (): void {
    $repository = readyToPush();
    $repository->git('rm', '--quiet', 'artisan');
    $repository->commit('No artisan');

    $process = $repository->runScript('deploy-check.sh', [], null, []);

    expect(steps($process->getOutput()))->toMatchArray(['boot' => 'FAIL'])
        ->and($process->getOutput())->toContain('no artisan file here');
});

it('says DO NOT PUSH with uncommitted or untracked files', function (string $change): void {
    $repository = readyToPush();

    match ($change) {
        'modified' => $repository->write('app/Greeter.php', $repository->read('app/Greeter.php')."\n"),
        'untracked' => $repository->write('notes.txt', "scratch\n"),
        default => throw new LogicException($change),
    };

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and(steps($process->getOutput()))->toMatchArray(['git' => 'FAIL'])
        ->and($process->getOutput())->toContain('uncommitted or untracked files');
})->with(['modified', 'untracked']);

it('says DO NOT PUSH when the protected branch moved on', function (): void {
    $repository = readyToPush();
    $repository->pushCommitFromElsewhere();

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('HEAD is 1 commit(s) behind origin/main (and 1 ahead)');
});

it('compares against the last fetch when FETCH is off', function (): void {
    $repository = readyToPush(['FETCH' => 'off']);
    $repository->pushCommitFromElsewhere();

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('1 commit(s) ahead of origin/main, 0 behind');
});

it('says DO NOT PUSH when there is nothing to push', function (): void {
    $repository = FixtureRepository::create();

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('Nothing to push');
});

it('checks against the configured protected branch', function (): void {
    $repository = readyToPush(['PROTECTED_BRANCH' => 'production']);

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('git fetch origin production failed');
});

it('reports a missing remote branch when not fetching', function (): void {
    $repository = readyToPush(['PROTECTED_BRANCH' => 'production', 'FETCH' => 'off']);

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getOutput())->toContain('origin/production does not exist');
});

it('skips steps listed in SKIP and says so', function (): void {
    $repository = readyToPush(['SKIP' => 'phpstan,boot']);
    // Wrong only to the analyser: the method is never called, so the suite still passes.
    $repository->write('app/Unused.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Unused\n{\n    public function length(): int\n    {\n        return strlen(123);\n    }\n}\n");
    $repository->stage('app/Unused.php');
    $repository->commit('Type error only phpstan would catch');

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(0)
        ->and(steps($process->getOutput()))->toBe(['pint' => 'PASS', 'phpstan' => 'SKIP', 'tests' => 'PASS', 'boot' => 'SKIP', 'git' => 'PASS'])
        ->and($process->getOutput())->toContain('Skipped by configuration: phpstan boot');

    // The same change fails once phpstan is no longer skipped.
    $repository->writeConfig(['PINT' => FixtureRepository::kitPath('vendor/bin/pint'), 'PHPSTAN' => FixtureRepository::kitPath('vendor/bin/phpstan'), 'SKIP' => 'boot']);
    $repository->commit('Stop skipping phpstan');

    expect(steps($repository->runScript('deploy-check.sh')->getOutput()))->toMatchArray(['phpstan' => 'FAIL']);
});

it('fails a step whose tool is missing instead of skipping it', function (): void {
    $repository = readyToPush(['PHPSTAN' => 'vendor/bin/phpstan']);

    $process = $repository->runScript('deploy-check.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('[FAIL] phpstan  vendor/bin/phpstan not found');
});

it('reports every failing step, not just the first', function (): void {
    $repository = readyToPush();
    $repository->write('app/Greeter.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Greeter\n{\n    public function greet(string \$name): int\n    {\n        return   'Hi';\n    }\n}\n");

    $process = $repository->runScript('deploy-check.sh');

    expect(steps($process->getOutput()))->toBe(['pint' => 'FAIL', 'phpstan' => 'FAIL', 'tests' => 'FAIL', 'boot' => 'PASS', 'git' => 'FAIL']);
});

it('exits 2 on a configuration error or a bad argument', function (): void {
    $repository = FixtureRepository::create(['SKIP' => 'lint']);

    $config = $repository->runScript('deploy-check.sh');
    $option = $repository->runScript('deploy-check.sh', ['--yes']);
    $outside = FixtureRepository::runKitScript('deploy-check.sh', [], FixtureRepository::plainDirectory());

    expect($config->getExitCode())->toBe(2)
        ->and($config->getErrorOutput())->toContain("SKIP has 'lint'")
        ->and($option->getExitCode())->toBe(2)
        ->and($outside->getExitCode())->toBe(2);
});

it('prints usage for --help', function (): void {
    $process = FixtureRepository::runKitScript('deploy-check.sh', ['--help'], FixtureRepository::plainDirectory());

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('VERDICT: PUSH')
        ->toContain('Usage: deploy-check.sh [directory]');
});
