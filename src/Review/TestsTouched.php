<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

/**
 * Checks the change as a whole: application code changed with no test.
 */
final class TestsTouched
{
    /**
     * @param  list<StagedFile>  $files
     */
    public function check(array $files): ?Finding
    {
        $application = array_filter($files, static fn (StagedFile $file): bool => $file->isPhp() && $file->isUnder('app'));
        $tests = array_filter($files, static fn (StagedFile $file): bool => $file->isUnder('tests'));

        if ($application === [] || $tests !== []) {
            return null;
        }

        $count = count($application);
        $noun = $count === 1 ? 'file' : 'files';

        return new Finding(
            rule: 'tests-not-touched',
            message: "{$count} application {$noun} under app/ changed and no file under tests/ is staged.",
        );
    }
}
