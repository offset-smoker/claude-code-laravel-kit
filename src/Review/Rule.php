<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

interface Rule
{
    /**
     * Checks one staged file. Rules only report on lines the change adds, so
     * a review is about the change and not about debt already in the file.
     *
     * @return list<Finding>
     */
    public function check(StagedFile $file): array;
}
