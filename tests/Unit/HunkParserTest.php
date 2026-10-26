<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Review\HunkParser;

it('reads added line numbers from zero-context hunks', function (): void {
    $diff = <<<'DIFF'
        diff --git a/app/Foo.php b/app/Foo.php
        index 1111111..2222222 100644
        --- a/app/Foo.php
        +++ b/app/Foo.php
        @@ -3 +3 @@ class Foo
        -    old
        +    new
        @@ -10,0 +11,2 @@ class Foo
        +    first
        +    second
        DIFF;

    expect(HunkParser::addedLines($diff))->toBe([3, 11, 12]);
});

it('counts context lines when the diff has them', function (): void {
    $diff = <<<'DIFF'
        @@ -1,3 +1,4 @@
         <?php
        +
         declare(strict_types=1);
        +// added
        DIFF;

    expect(HunkParser::addedLines($diff))->toBe([2, 4]);
});

it('numbers every line of a new file', function (): void {
    $diff = <<<'DIFF'
        --- /dev/null
        +++ b/app/New.php
        @@ -0,0 +1,3 @@
        +<?php
        +
        +echo 1;
        \ No newline at end of file
        DIFF;

    expect(HunkParser::addedLines($diff))->toBe([1, 2, 3]);
});

it('returns nothing for a diff that only deletes', function (): void {
    $diff = <<<'DIFF'
        --- a/app/Foo.php
        +++ b/app/Foo.php
        @@ -4,2 +3,0 @@
        -    gone
        -    also gone
        DIFF;

    expect(HunkParser::addedLines($diff))->toBe([]);
});

it('does not mistake file headers for added lines', function (): void {
    expect(HunkParser::addedLines("+++ b/app/Foo.php\n+not in a hunk\n"))->toBe([]);
});
