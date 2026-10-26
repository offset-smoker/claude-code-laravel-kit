<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

/**
 * Reads the added line numbers out of a unified diff of one file.
 */
final class HunkParser
{
    /**
     * @return list<int> 1-based line numbers in the new version of the file
     */
    public static function addedLines(string $diff): array
    {
        $added = [];
        $next = null;

        foreach (Lines::split($diff) as $row) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $row, $match) === 1) {
                $next = (int) $match[1];

                continue;
            }

            if ($next === null || str_starts_with($row, '\\')) {
                continue;
            }

            if (str_starts_with($row, '+')) {
                $added[] = $next;
                $next++;

                continue;
            }

            if (str_starts_with($row, ' ')) {
                $next++;
            }
        }

        return $added;
    }
}
