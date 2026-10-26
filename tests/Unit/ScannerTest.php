<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Report;
use OffsetSmoker\LaravelKit\Review\Scanner;
use OffsetSmoker\LaravelKit\Review\StagedFile;

it('runs every rule over every file and adds the change-set check', function (): void {
    $findings = Scanner::default()->scan([
        new StagedFile('app/Models/Post.php', "<?php\nprotected \$guarded = [];\n\$x = env('X');", [2, 3]),
        new StagedFile('.env', 'APP_KEY=base64:secret', [1]),
    ]);

    expect(array_map(static fn (Finding $finding): string => $finding->rule, $findings))
        ->toBe(['mass-assignment', 'env-outside-config', 'secret', 'tests-not-touched']);
});

it('does not ask for tests when tests are staged too', function (): void {
    $findings = Scanner::default()->scan([
        new StagedFile('app/Greeter.php', "<?php\necho 1;", [2]),
        new StagedFile('tests/Unit/GreeterTest.php', "<?php\nit('greets');", [2]),
    ]);

    expect($findings)->toBe([]);
});

it('does not ask for tests when no application code changed', function (): void {
    expect(Scanner::default()->scan([new StagedFile('README.md', 'Docs', [1])]))->toBe([]);
});

it('renders a text report with locations and snippets', function (): void {
    $findings = Scanner::default()->scan([
        new StagedFile('app/Models/Post.php', "<?php\nprotected \$guarded = [];", [2]),
        new StagedFile('tests/Unit/PostTest.php', '<?php', [1]),
    ]);

    expect(Report::text($findings, 2))->toBe(
        "app/Models/Post.php:2 [mass-assignment] The model is unguarded. Declare an explicit \$fillable list instead.\n"
        ."    protected \$guarded = [];\n"
        ."laravel-kit review scan: 2 staged text files scanned, 1 finding.\n",
    );
});

it('says so when there is nothing to report', function (): void {
    expect(Report::text([], 1))->toBe("laravel-kit review scan: 1 staged text file scanned, no findings.\n");
});

it('truncates very long snippets', function (): void {
    $finding = new Finding('debug-call', 'Debug call.', 'app/Foo.php', 1, str_repeat('x', 300));

    expect(Report::text([$finding], 1))->toContain(str_repeat('x', 157).'...');
});

it('renders a JSON report', function (): void {
    $report = Report::json([new Finding('tests-not-touched', 'No tests.')], 3);

    expect(json_decode($report, true))->toBe([
        'files' => 3,
        'findings' => [['rule' => 'tests-not-touched', 'message' => 'No tests.', 'path' => null, 'line' => null, 'snippet' => null]],
    ]);
});
