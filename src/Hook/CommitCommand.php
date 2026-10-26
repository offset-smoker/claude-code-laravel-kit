<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Hook;

final readonly class CommitCommand
{
    /**
     * @param  bool  $includesUnstaged  the commit also takes working-tree changes (-a, pathspecs)
     * @param  list<string>  $pathspecs  the paths the commit names, relative to $directory; empty means no limit
     * @param  bool  $onlyPathspecs  staged changes outside $pathspecs stay out of the commit (git's --only, the default with paths)
     */
    public function __construct(
        public string $directory,
        public bool $includesUnstaged,
        public array $pathspecs = [],
        public bool $onlyPathspecs = false,
    ) {}

    /**
     * How the fast gate should collect the commit's files: staged, all
     * (every unstaged tracked change too), only (just the named paths) or
     * include (staged plus the named paths).
     */
    public function mode(): string
    {
        return match (true) {
            ! $this->includesUnstaged => 'staged',
            $this->pathspecs === [] => 'all',
            $this->onlyPathspecs => 'only',
            default => 'include',
        };
    }
}
