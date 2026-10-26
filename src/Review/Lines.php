<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

final class Lines
{
    /**
     * Splits text into lines the way git numbers them: on \n only, dropping
     * the \r of a \r\n ending. A lone \r stays inside its line.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        return array_map(
            static fn (string $line): string => str_ends_with($line, "\r") ? substr($line, 0, -1) : $line,
            explode("\n", $text),
        );
    }
}
