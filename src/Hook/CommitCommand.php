<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Hook;

final readonly class CommitCommand
{
    /**
     * @param  bool  $includesUnstaged  the commit also takes working-tree changes (-a, pathspecs)
     */
    public function __construct(
        public string $directory,
        public bool $includesUnstaged,
    ) {}
}
