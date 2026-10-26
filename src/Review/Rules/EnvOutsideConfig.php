<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review\Rules;

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\StagedFile;

final class EnvOutsideConfig implements Rule
{
    private const string ENV_CALL = '/(?<![\w>:$])env\s*\(/';

    public function check(StagedFile $file): array
    {
        if (! $file->isPhp() || $file->isUnder('config')) {
            return [];
        }

        $findings = [];

        foreach ($file->addedLines() as $number) {
            $line = $file->line($number);

            if (preg_match('/\bfunction\s+env\s*\(/', $line) === 1) {
                continue;
            }

            if (preg_match(self::ENV_CALL, $line) === 1) {
                $findings[] = Finding::at('env-outside-config', $file, $number, 'env() outside config/ returns null once `php artisan config:cache` has run. Read the value in a config file and use config() here.');
            }
        }

        return $findings;
    }
}
