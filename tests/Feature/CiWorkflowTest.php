<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;

function kitFile(string $relative): string
{
    $contents = file_get_contents(FixtureRepository::kitPath($relative));

    return is_string($contents) ? $contents : '';
}

it('pins every action to a full commit SHA with its version in a comment', function (): void {
    preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/m', kitFile('.github/workflows/ci.yml'), $uses, PREG_SET_ORDER);

    expect($uses)->not->toBeEmpty();

    foreach ($uses as [, $action, $comment]) {
        expect($action)->toMatch('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/')
            ->and($comment)->toMatch('/^\s+# v?\d+\.\d+\.\d+$/');
    }
});

it('installs an exact Claude Code version', function (): void {
    expect(kitFile('.github/workflows/ci.yml'))->toMatch('/npm install --global @anthropic-ai\/claude-code@\d+\.\d+\.\d+\n/');
});

it('has Dependabot update the actions and Composer dependencies', function (): void {
    $config = kitFile('.github/dependabot.yml');

    expect($config)->toContain('package-ecosystem: github-actions')
        ->toContain('package-ecosystem: composer');
});
