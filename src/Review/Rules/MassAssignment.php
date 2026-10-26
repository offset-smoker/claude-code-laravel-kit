<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review\Rules;

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\StagedFile;

final class MassAssignment implements Rule
{
    private const string UNGUARDED = '/\$guarded\s*=\s*(\[\s*\]|array\(\s*\))|\bModel::unguard\s*\(/';

    private const string WHOLE_REQUEST = '/(?:create|update|fill|forceFill|forceCreate|updateOrCreate|firstOrCreate|firstOrNew|insert|upsert)\s*\(\s*(?:\$request->(?:all|input|post)\(\s*\)|request\(\)->(?:all|input|post)\(\s*\)|Request::(?:all|input)\(\s*\))/';

    public function check(StagedFile $file): array
    {
        if (! $file->isPhp()) {
            return [];
        }

        $findings = [];

        foreach ($file->addedLines() as $number) {
            $line = $file->line($number);

            if (preg_match(self::UNGUARDED, $line) === 1) {
                $findings[] = Finding::at('mass-assignment', $file, $number, 'The model is unguarded. Declare an explicit $fillable list instead.');

                continue;
            }

            if (preg_match(self::WHOLE_REQUEST, $line) === 1) {
                $findings[] = Finding::at('mass-assignment', $file, $number, 'The whole request is written to the model. Pass $request->validated() or an explicit only([...]) list.');
            }
        }

        return $findings;
    }
}
