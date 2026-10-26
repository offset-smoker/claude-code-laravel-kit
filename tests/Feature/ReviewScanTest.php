<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;

const LEAKED_TOKEN = 'sk-ant-api03-ZqT8vW2nR5yL0pK7mX3cB9hJ4dF6gS1a';

const RISKY_CONTROLLER = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App\Http\Controllers;

    use App\Models\Post;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;

    final class PostController extends Controller
    {
        public function update(Request $request, Post $post): void
        {
            $post->update($request->all());
            DB::statement("update posts set views = views + 1 where id = {$post->id}");
            $token = env('REPORTING_TOKEN');
        }
    }
    PHP;

it('reports findings in the staged change and exits 1', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Http/Controllers/PostController.php', RISKY_CONTROLLER);
    $repository->stage('app/Http/Controllers/PostController.php');

    $process = $repository->runScript('review-scan.php');
    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(1)
        ->and($output)->toContain('app/Http/Controllers/PostController.php:13 [missing-authorization]')
        ->toContain('app/Http/Controllers/PostController.php:15 [mass-assignment]')
        ->toContain('app/Http/Controllers/PostController.php:16 [raw-sql]')
        ->toContain('app/Http/Controllers/PostController.php:17 [env-outside-config]')
        ->toContain('(change set) [tests-not-touched]')
        ->toContain('1 staged text file scanned, 5 findings.');
});

it('reports only lines the change adds', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Http/Controllers/PostController.php', RISKY_CONTROLLER);
    $repository->stage('app/Http/Controllers/PostController.php');
    $repository->git('commit', '--quiet', '-m', 'Existing debt');

    $repository->write('app/Http/Controllers/PostController.php', str_replace('$token = env', "\$this->authorize('update', \$post);\n        \$token = env", RISKY_CONTROLLER));
    $repository->write('tests/PostTest.php', "<?php\n");
    $repository->stage('app/Http/Controllers/PostController.php', 'tests/PostTest.php');

    $process = $repository->runScript('review-scan.php');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe("laravel-kit review scan: 2 staged text files scanned, no findings.\n");
});

it('reads the staged version, not the working tree', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Http/Controllers/PostController.php', RISKY_CONTROLLER);
    $repository->stage('app/Http/Controllers/PostController.php');
    $repository->write('app/Http/Controllers/PostController.php', "<?php\n");

    expect($repository->runScript('review-scan.php')->getOutput())->toContain('[mass-assignment]');
});

it('never prints a secret it finds', function (): void {
    $key = 'sk_live_'.str_repeat('4', 24);
    $repository = FixtureRepository::create();
    $repository->write('app/Billing.php', "<?php\n\n\$stripe = '{$key}';\n");
    $repository->write('.env', "STRIPE_SECRET={$key}\n");
    $repository->stage('app/Billing.php', '.env');

    $text = $repository->runScript('review-scan.php');
    $json = $repository->runScript('review-scan.php', ['--format=json']);

    expect($text->getOutput())->toContain('app/Billing.php:3 [secret]')
        ->toContain('.env [secret] An environment file is staged')
        ->not->toContain($key)
        ->and($json->getOutput())->not->toContain($key);
});

it('never prints a secret that shares a line with another finding', function (string $line, string $otherRule, string $secret): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Http/Controllers/KeyController.php', "<?php\n\nfinal class KeyController extends Controller\n{\n    public function store(Request \$request): void\n    {\n        {$line}\n    }\n}\n");
    $repository->stage('app/Http/Controllers/KeyController.php');

    $text = $repository->runScript('review-scan.php');
    $json = $repository->runScript('review-scan.php', ['--format=json']);

    expect($text->getOutput())->toContain('KeyController.php:7 [secret]')
        ->toContain("KeyController.php:7 [{$otherRule}]")
        ->not->toContain($secret)
        ->and($json->getOutput())->toContain("\"rule\": \"{$otherRule}\"")
        ->not->toContain($secret);
})->with([
    'env() default' => ["\$key = env('ANTHROPIC_KEY', '".LEAKED_TOKEN."');", 'env-outside-config', LEAKED_TOKEN],
    'debug call' => ["dump('".LEAKED_TOKEN."');", 'debug-call', LEAKED_TOKEN],
    'raw SQL' => ["DB::statement(\"update keys set value = '".LEAKED_TOKEN."' where id = {\$request->id}\");", 'raw-sql', LEAKED_TOKEN],
    'mass assignment' => ["Key::create(\$request->all() + ['token' => '".LEAKED_TOKEN."']);", 'mass-assignment', LEAKED_TOKEN],
    'literal password beside env()' => ["\$db = ['password' => 'hunter2-but-longer', 'host' => env('DB_HOST')];", 'env-outside-config', 'hunter2-but-longer'],
]);

it('prints no line from a staged environment file', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('config/.env.php', "<?php\n\ndump('plain-value-1234');\n");
    $repository->stage('config/.env.php');

    $output = $repository->runScript('review-scan.php')->getOutput();

    expect($output)->toContain('config/.env.php [secret]')
        ->toContain('config/.env.php:3 [debug-call]')
        ->not->toContain('plain-value-1234');
});

it('prints JSON when asked', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Debug.php', "<?php\n\ndd('here');\n");
    $repository->write('tests/DebugTest.php', "<?php\n");
    $repository->stage('app/Debug.php', 'tests/DebugTest.php');

    $process = $repository->runScript('review-scan.php', ['--format=json']);

    expect($process->getExitCode())->toBe(1)
        ->and(json_decode($process->getOutput(), true))->toBe([
            'files' => 2,
            'findings' => [[
                'rule' => 'debug-call',
                'message' => 'A debugging call is being committed.',
                'path' => 'app/Debug.php',
                'line' => 3,
                'snippet' => "dd('here');",
            ]],
        ]);
});

it('scans the directory given as an argument', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('app/Debug.php', "<?php\n\ndump(1);\n");
    $repository->stage('app/Debug.php');

    $process = FixtureRepository::runKitScript('review-scan.php', [$repository->path.'/app'], FixtureRepository::plainDirectory(), null, $repository->environment());

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('app/Debug.php:3 [debug-call]');
});

it('skips binary files', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('public/logo.png', "\x89PNG\r\n\x1a\n\0\0\0dd(");
    $repository->stage('public/logo.png');

    $process = $repository->runScript('review-scan.php');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('0 staged text files scanned, no findings');
});

it('flags a staged binary key store by its name', function (): void {
    $repository = FixtureRepository::create();
    $repository->write('certs/client.p12', "0\x82\x0a\x01\x02\x01\x030\0\0\x06\x09*\x86H");
    $repository->stage('certs/client.p12');

    $process = $repository->runScript('review-scan.php');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('certs/client.p12 [secret] A private key file is staged')
        ->toContain('0 staged text files scanned, 1 finding.');
});

it('exits 2 outside a repository or with bad arguments', function (array $arguments): void {
    $process = FixtureRepository::runKitScript('review-scan.php', $arguments, FixtureRepository::plainDirectory());

    expect($process->getExitCode())->toBe(2);
})->with([
    'not a repository' => [[]],
    'missing directory' => [['/nonexistent/laravel-kit']],
    'unknown option' => [['--fix']],
    'bad format' => [['--format=xml']],
]);
