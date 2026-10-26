<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

use OffsetSmoker\LaravelKit\Git\Git;

final readonly class StagedChanges
{
    public function __construct(private Git $git) {}

    /**
     * Staged text files, read from the index rather than the working tree,
     * so the review sees exactly what the commit will contain.
     *
     * @return list<StagedFile>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->git->stagedFiles() as $path) {
            $contents = $this->git->stagedContents($path);

            if (str_contains($contents, "\0")) {
                continue;
            }

            $files[] = new StagedFile(
                path: $path,
                contents: $contents,
                addedLines: HunkParser::addedLines($this->git->stagedDiff($path)),
            );
        }

        return $files;
    }
}
