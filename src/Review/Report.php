<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

use JsonException;

final class Report
{
    /**
     * @param  list<Finding>  $findings
     */
    public static function text(array $findings, int $fileCount): string
    {
        $files = $fileCount === 1 ? '1 staged text file scanned' : "{$fileCount} staged text files scanned";

        if ($findings === []) {
            return "laravel-kit review scan: {$files}, no findings.\n";
        }

        $out = '';

        foreach ($findings as $finding) {
            $out .= "{$finding->location()} [{$finding->rule}] {$finding->message}\n";

            if ($finding->snippet !== null && $finding->snippet !== '') {
                $out .= '    '.(strlen($finding->snippet) > 160 ? substr($finding->snippet, 0, 157).'...' : $finding->snippet)."\n";
            }
        }

        $count = count($findings);
        $noun = $count === 1 ? 'finding' : 'findings';

        return $out."laravel-kit review scan: {$files}, {$count} {$noun}.\n";
    }

    /**
     * @param  list<Finding>  $findings
     *
     * @throws JsonException
     */
    public static function json(array $findings, int $fileCount): string
    {
        return json_encode([
            'files' => $fileCount,
            'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
