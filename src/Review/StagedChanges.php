<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

use OffsetSmoker\LaravelKit\Git\Git;

final readonly class StagedChanges
{
    public function __construct(private Git $git) {}

    /**
     * Staged files, read from the index rather than the working tree, so the
     * review sees exactly what the commit will contain. Text files come back
     * whole; binary files only by path, for the rules that judge a file by
     * its name.
     *
     * @return array{list<StagedFile>, list<string>} text files, binary paths
     */
    public function read(): array
    {
        $files = [];
        $binaryPaths = [];

        foreach ($this->git->stagedFiles() as $path) {
            $contents = $this->git->stagedContents($path);

            if (str_contains($contents, "\0")) {
                $binaryPaths[] = $path;

                continue;
            }

            $files[] = new StagedFile(
                path: $path,
                contents: $contents,
                addedLines: HunkParser::addedLines($this->git->stagedDiff($path)),
            );
        }

        return [$files, $binaryPaths];
    }
}
