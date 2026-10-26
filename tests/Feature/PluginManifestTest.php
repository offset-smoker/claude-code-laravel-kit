<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;

/**
 * @return array<mixed>
 */
function jsonFile(string $relative): array
{
    $decoded = json_decode((string) file_get_contents(FixtureRepository::kitPath($relative)), true, 64, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array<string, string>
 */
function frontmatter(string $relative): array
{
    $contents = (string) file_get_contents(FixtureRepository::kitPath($relative));

    if (preg_match('/\A---\n(.*?)\n---\n/s', $contents, $match) !== 1) {
        return [];
    }

    $fields = [];

    foreach (explode("\n", $match[1]) as $line) {
        if (preg_match('/^([a-z-]+):\s*(.*)$/', $line, $field) === 1) {
            $fields[$field[1]] = trim($field[2], '"');
        }
    }

    return $fields;
}

it('has a plugin manifest whose name Claude Code accepts', function (): void {
    $manifest = jsonFile('.claude-plugin/plugin.json');

    expect($manifest['name'] ?? null)->toBe('laravel-kit')
        ->and($manifest['name'])->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
        ->not->toStartWith('claude-')
        ->and($manifest['version'] ?? null)->toBe('0.1.0');
});

it('lists the plugin at the marketplace root under the same name', function (): void {
    $marketplace = jsonFile('.claude-plugin/marketplace.json');
    $plugins = $marketplace['plugins'] ?? [];

    expect($marketplace['name'] ?? null)->toBe('offset-smoker')
        ->and($marketplace['owner'] ?? null)->toHaveKey('name')
        ->and($plugins)->toBeArray()->toHaveCount(1)
        ->and($plugins[0]['name'] ?? null)->toBe(jsonFile('.claude-plugin/plugin.json')['name'] ?? '')
        ->and($plugins[0]['source'] ?? null)->toBe('./');
});

it('registers the commit gate as a PreToolUse hook on Bash', function (): void {
    $hooks = jsonFile('hooks/hooks.json');
    $handler = $hooks['hooks']['PreToolUse'][0]['hooks'][0] ?? [];

    expect($hooks['hooks']['PreToolUse'][0]['matcher'] ?? null)->toBe('Bash')
        ->and($handler['type'] ?? null)->toBe('command')
        ->and($handler['if'] ?? null)->toBe('Bash(git *)')
        ->and($handler['command'] ?? null)->toBe('"${CLAUDE_PLUGIN_ROOT}"/scripts/commit-gate.sh');
});

it('ships every script it refers to, executable', function (string $script): void {
    expect(is_executable(FixtureRepository::kitPath('scripts/'.$script)))->toBeTrue();
})->with(['commit-gate.sh', 'fast-gate.sh', 'deploy-check.sh']);

it('has both skills with a name and a description', function (string $skill): void {
    $fields = frontmatter("skills/{$skill}/SKILL.md");

    expect($fields['name'] ?? null)->toBe($skill)
        ->and(strlen($fields['description'] ?? ''))->toBeGreaterThan(80)->toBeLessThan(1536);
})->with(['review', 'deploy-check']);

it('only points skills at files that exist', function (string $skill): void {
    $contents = (string) file_get_contents(FixtureRepository::kitPath("skills/{$skill}/SKILL.md"));
    preg_match_all('#\$\{CLAUDE_PLUGIN_ROOT\}/([\w./-]+)#', $contents, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $path) {
        expect(FixtureRepository::kitPath($path))->toBeFile();
    }
})->with(['review', 'deploy-check']);

it('keeps the plugin version in step with the changelog', function (): void {
    expect((string) file_get_contents(FixtureRepository::kitPath('CHANGELOG.md')))
        ->toContain('## 0.1.0');
});
