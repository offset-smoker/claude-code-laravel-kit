<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Hook;

use JsonException;

/**
 * The part of a PreToolUse payload the commit gate needs. Anything that is
 * not a well-formed Bash call yields null, and the gate stays out of the way.
 */
final readonly class HookInput
{
    private function __construct(
        public string $command,
        public string $cwd,
    ) {}

    public static function fromJson(string $json): ?self
    {
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['tool_name'] ?? null) !== 'Bash') {
            return null;
        }

        $input = $payload['tool_input'] ?? null;
        $cwd = $payload['cwd'] ?? null;

        if (! is_array($input) || ! is_string($input['command'] ?? null) || ! is_string($cwd) || $cwd === '') {
            return null;
        }

        return new self($input['command'], $cwd);
    }
}
