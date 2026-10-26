<?php

declare(strict_types=1);

/*
 * Scans the staged change for the mechanical Laravel review findings.
 *
 * Usage: php review-scan.php [--format=text|json] [directory]
 * Exit codes: 0 no findings, 1 findings, 2 usage error or not a git repository.
 */

use OffsetSmoker\LaravelKit\Git\Git;
use OffsetSmoker\LaravelKit\Review\Report;
use OffsetSmoker\LaravelKit\Review\Scanner;
use OffsetSmoker\LaravelKit\Review\StagedChanges;

require __DIR__.'/../src/autoload.php';

$format = 'text';
$directory = getcwd();

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--format=')) {
        $format = substr($argument, strlen('--format='));

        continue;
    }

    if (str_starts_with($argument, '-')) {
        fwrite(STDERR, "Unknown option: {$argument}\nUsage: review-scan.php [--format=text|json] [directory]\n");
        exit(2);
    }

    $directory = $argument;
}

if (! in_array($format, ['text', 'json'], true) || ! is_string($directory)) {
    fwrite(STDERR, "Usage: review-scan.php [--format=text|json] [directory]\n");
    exit(2);
}

try {
    [$files, $binaryPaths] = (new StagedChanges(Git::discover($directory)))->read();
} catch (RuntimeException $exception) {
    fwrite(STDERR, "laravel-kit review scan: {$exception->getMessage()}\n");
    exit(2);
}

$findings = Scanner::default()->scan($files, $binaryPaths);

fwrite(STDOUT, $format === 'json' ? Report::json($findings, count($files)) : Report::text($findings, count($files)));

exit($findings === [] ? 0 : 1);
