<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review\Rules;

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\StagedFile;

final class DebugCall implements Rule
{
    private const string DEBUG_CALL = '/(?<![\w>:$])(?:dd|dump|ddd|ray|var_dump)\s*\(/';

    public function check(StagedFile $file): array
    {
        if (! $file->isPhp()) {
            return [];
        }

        $findings = [];

        foreach ($file->addedLines() as $number) {
            $line = $file->line($number);

            if (preg_match('/\bfunction\s+\w+\s*\(/', $line) === 1) {
                continue;
            }

            if (preg_match(self::DEBUG_CALL, $line) === 1) {
                $findings[] = Finding::at('debug-call', $file, $number, 'A debugging call is being committed.');
            }
        }

        return $findings;
    }
}
