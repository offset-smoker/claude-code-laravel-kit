<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Hook\HookInput;

it('reads the command and cwd from a Bash PreToolUse payload', function (): void {
    $input = HookInput::fromJson((string) json_encode([
        'session_id' => 'abc',
        'hook_event_name' => 'PreToolUse',
        'tool_name' => 'Bash',
        'tool_input' => ['command' => 'git commit -m x', 'description' => 'Commit'],
        'cwd' => '/repo',
    ]));

    expect($input?->command)->toBe('git commit -m x')
        ->and($input?->cwd)->toBe('/repo');
});

it('returns null for anything else', function (string $json): void {
    expect(HookInput::fromJson($json))->toBeNull();
})->with([
    'not json' => ['{nope'],
    'scalar' => ['"git commit"'],
    'another tool' => ['{"tool_name":"Write","tool_input":{"command":"git commit"},"cwd":"/repo"}'],
    'no command' => ['{"tool_name":"Bash","tool_input":{},"cwd":"/repo"}'],
    'command not a string' => ['{"tool_name":"Bash","tool_input":{"command":["git"]},"cwd":"/repo"}'],
    'no cwd' => ['{"tool_name":"Bash","tool_input":{"command":"git commit"}}'],
    'empty cwd' => ['{"tool_name":"Bash","tool_input":{"command":"git commit"},"cwd":""}'],
    'too deep' => [str_repeat('[', 40).str_repeat(']', 40)],
]);
