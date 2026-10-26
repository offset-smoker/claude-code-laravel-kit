<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review\Rules;

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\StagedFile;

/**
 * A public Livewire property can be rewritten by the browser. One that holds
 * a record id and is used to load data needs #[Locked].
 */
final class UnlockedLivewireId implements Rule
{
    private const string ID_PROPERTY = '/^\s*public\s+(?:readonly\s+)?(?:\??[\w\\\\|]+\s+)?\$(\w*(?:Id|_id)|id)\b/';

    public function check(StagedFile $file): array
    {
        if (! $file->isLivewireComponent()) {
            return [];
        }

        $findings = [];

        foreach ($file->addedLines() as $number) {
            if (preg_match(self::ID_PROPERTY, $file->line($number), $match) !== 1) {
                continue;
            }

            if ($this->isLocked($file, $number)) {
                continue;
            }

            $findings[] = Finding::at('unlocked-livewire-id', $file, $number, "Public property \${$match[1]} can be changed from the browser. Add #[Locked], and still scope the lookup to what the user may see.");
        }

        return $findings;
    }

    private function isLocked(StagedFile $file, int $number): bool
    {
        if (str_contains($file->line($number), '#[Locked')) {
            return true;
        }

        for ($previous = $number - 1; $previous >= max(1, $number - 4); $previous--) {
            $line = trim($file->line($previous));

            if (str_contains($line, '#[Locked')) {
                return true;
            }

            if ($line !== '' && ! str_starts_with($line, '#[') && ! str_starts_with($line, '/') && ! str_starts_with($line, '*')) {
                return false;
            }
        }

        return false;
    }
}
