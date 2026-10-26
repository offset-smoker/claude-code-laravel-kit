<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

final class Lines
{
    /**
     * Splits text on any line break: \n, \r\n or \r.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        $lines = preg_split('/\R/', $text);

        return $lines === false ? [] : $lines;
    }
}
