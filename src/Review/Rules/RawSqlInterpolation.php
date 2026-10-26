<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review\Rules;

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\StagedFile;

final class RawSqlInterpolation implements Rule
{
    private const string RAW_CALL = '/(?:\bDB::(?:raw|select|selectOne|scalar|statement|unprepared|insert|update|delete|cursor)|->(?:whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|orHavingRaw|groupByRaw|fromRaw|joinRaw)|\bDB::connection\([^)]*\)->(?:select|statement|unprepared|insert|update|delete))\s*\(/';

    /** A variable inside a double-quoted string, a concatenation with a variable, or sprintf. */
    private const string INTERPOLATION = '/"[^"]*(?:\$[A-Za-z_]|\{\$)|\.\s*\$[A-Za-z_]|\$[A-Za-z_][\w\->\[\]\'"]*\s*\.\s*[\'"]|\bsprintf\s*\(/';

    public function check(StagedFile $file): array
    {
        if (! $file->isPhp()) {
            return [];
        }

        $findings = [];

        foreach ($file->addedLines() as $number) {
            $line = $file->line($number);

            if (preg_match(self::RAW_CALL, $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $arguments = substr($line, $match[0][1]);

            if (preg_match(self::INTERPOLATION, $arguments) === 1) {
                $findings[] = Finding::at('raw-sql', $file, $number, 'A value is interpolated into raw SQL. Use ? placeholders and pass the values as bindings.');
            }
        }

        return $findings;
    }
}
