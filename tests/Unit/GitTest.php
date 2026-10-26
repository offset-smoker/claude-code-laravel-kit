<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;
use Symfony\Component\Process\Process;

it('reads a git command that writes more to stderr than a pipe holds', function (): void {
    $repository = FixtureRepository::createEmpty();
    $script = $repository->path.'/run-git.php';
    $repository->write('run-git.php', sprintf(
        '<?php require %s; echo (new OffsetSmoker\LaravelKit\Git\Git(%s))->run(["-c", %s, "noisy"]);',
        var_export(FixtureRepository::kitPath('src/autoload.php'), true),
        var_export($repository->path, true),
        var_export('alias.noisy=!head -c 300000 /dev/zero | tr "\\0" x >&2; echo done', true),
    ));

    $process = new Process([PHP_BINARY, $script], $repository->path, $repository->environment(), null, 20);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe("done\n");
});
