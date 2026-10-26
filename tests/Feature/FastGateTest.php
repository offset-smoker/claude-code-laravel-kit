<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;

const UNFORMATTED = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return   'Hello, '.\$name;\n    }\n}\n";

const TYPE_ERROR = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Greeter\n{\n    public function greet(string \$name): int\n    {\n        return \"Hello, {\$name}\";\n    }\n}\n";

it('passes when no PHP file is staged', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('README.md', "# Fixture\n");
    $repository->stage('README.md');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('no PHP files in this commit');
});

it('passes clean staged PHP files', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Farewell.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Farewell\n{\n    public function say(): string\n    {\n        return 'Bye';\n    }\n}\n");
    $repository->stage('app/Farewell.php');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('checking 1 PHP file(s)')
        ->toContain('PASS pint --test')
        ->toContain('PASS phpstan');
});

it('fails when a staged file is not formatted', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', UNFORMATTED);
    $repository->stage('app/Greeter.php');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('FAIL pint --test')
        ->toContain('app/Greeter.php')
        ->toContain('PASS phpstan');
});

it('fails when PHPStan finds an error in a staged file', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', TYPE_ERROR);
    $repository->stage('app/Greeter.php');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('FAIL phpstan')
        ->toContain('should return int but returns string');
});

it('checks only staged files unless asked to include unstaged ones', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', TYPE_ERROR);

    $stagedOnly = $repository->runScript('fast-gate.sh');
    $withUnstaged = $repository->runScript('fast-gate.sh', ['--include-unstaged']);

    expect($stagedOnly->getExitCode())->toBe(0)
        ->and($withUnstaged->getExitCode())->toBe(1)
        ->and($withUnstaged->getOutput())->toContain('FAIL phpstan');
});

it('limits the check to the paths a commit names', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', TYPE_ERROR);
    $repository->write('routes/web.php', $repository->read('routes/web.php')."// More routes.\n");

    $greeter = $repository->runScript('fast-gate.sh', ['--include-unstaged', '.', '--', 'app/Greeter.php']);
    $routes = $repository->runScript('fast-gate.sh', ['--include-unstaged', '.', '--', 'routes/web.php']);
    $fromSubdirectory = $repository->runScript('fast-gate.sh', ['--include-unstaged', 'app', '--', 'Greeter.php']);

    expect($greeter->getExitCode())->toBe(1)
        ->and($routes->getExitCode())->toBe(0)
        ->and($routes->getOutput())->toContain('checking 1 PHP file(s)')
        ->and($fromSubdirectory->getExitCode())->toBe(1);
});

it('leaves out staged files outside the named paths for an --only commit', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Greeter.php', TYPE_ERROR);
    $repository->stage('app/Greeter.php');
    $repository->write('routes/web.php', $repository->read('routes/web.php')."// More routes.\n");

    $only = $repository->runScript('fast-gate.sh', ['--include-unstaged', '--only', '.', '--', 'routes/web.php']);
    $include = $repository->runScript('fast-gate.sh', ['--include-unstaged', '.', '--', 'routes/web.php']);

    expect($only->getExitCode())->toBe(0)
        ->and($include->getExitCode())->toBe(1);
});

it('skips the checks named in FAST_GATE_SKIP', function (): void {
    $repository = FixtureRepository::create();
    $repository->writeConfig([
        'PINT' => FixtureRepository::kitPath('vendor/bin/pint'),
        'PHPSTAN' => FixtureRepository::kitPath('vendor/bin/phpstan'),
        'FAST_GATE_SKIP' => 'phpstan',
    ]);
    $repository->write('app/Greeter.php', TYPE_ERROR);
    $repository->stage('app/Greeter.php');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('SKIP phpstan (FAST_GATE_SKIP)');
});

it('skips a tool that is not installed and says so', function (): void {
    $repository = FixtureRepository::create(['PINT' => 'vendor/bin/pint', 'PHPSTAN' => 'vendor/bin/phpstan']);
    $repository->write('app/Greeter.php', UNFORMATTED);
    $repository->stage('app/Greeter.php');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('SKIP pint: vendor/bin/pint not found')
        ->toContain('SKIP phpstan: vendor/bin/phpstan not found');
});

it('handles file names with spaces', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Odd Name.php', UNFORMATTED);
    $repository->stage('app/Odd Name.php');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('checking 1 PHP file(s)')
        ->toContain('FAIL pint --test');
});

it('rejects an invalid configuration file', function (string $line, string $message): void {
    $repository = FixtureRepository::create();
    $repository->write('.laravel-kit.conf', $line."\n");

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($message);
})->with([
    'unknown key' => ['PHPSTAN_LEVEL=9', "unknown key 'PHPSTAN_LEVEL'"],
    'not key=value' => ['just words', 'expected KEY=value'],
    'bad switch' => ['FAST_GATE=maybe', 'FAST_GATE must be on or off'],
    'bad skip entry' => ['FAST_GATE_SKIP=tests', "FAST_GATE_SKIP has 'tests'"],
    'bad memory limit' => ['PHPSTAN_MEMORY_LIMIT=lots', 'PHPSTAN_MEMORY_LIMIT'],
    'bad remote' => ['REMOTE=a;b', "REMOTE 'a;b' is not a valid remote name"],
    'bad branch' => ['PROTECTED_BRANCH=a..b', "PROTECTED_BRANCH 'a..b' is not a valid branch name"],
]);

it('reads quoted values, comments and CRLF line endings', function (): void {
    $repository = FixtureRepository::create();
    $pint = FixtureRepository::kitPath('vendor/bin/pint');
    $phpstan = FixtureRepository::kitPath('vendor/bin/phpstan');
    $repository->write('.laravel-kit.conf', "# team settings\r\n\r\nPINT=\"{$pint}\"\r\n  PHPSTAN = '{$phpstan}'  \r\nFAST_GATE_SKIP=pint");
    $repository->write('app/Greeter.php', UNFORMATTED);
    $repository->stage('app/Greeter.php');

    $process = $repository->runScript('fast-gate.sh');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('SKIP pint (FAST_GATE_SKIP)')
        ->toContain('PASS phpstan');
});

it('refuses to run outside a git repository', function (): void {
    $process = FixtureRepository::runKitScript('fast-gate.sh', [], FixtureRepository::plainDirectory());

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('is not inside a git repository');
});

it('rejects unknown options', function (): void {
    $repository = FixtureRepository::create();

    expect($repository->runScript('fast-gate.sh', ['--fast'])->getExitCode())->toBe(2);
});
