<?php

declare(strict_types=1);

arch('the kit follows the PHP preset')
    ->preset()
    ->php();

arch('the kit is strict')
    ->preset()
    ->strict()
    ->ignoring('OffsetSmoker\LaravelKit\Tests');

arch('the kit avoids insecure functions')
    ->preset()
    ->security()
    ->ignoring('OffsetSmoker\LaravelKit\Tests');

arch('the kit never runs commands through a shell')
    ->expect('OffsetSmoker\LaravelKit')
    ->not->toUse(['exec', 'shell_exec', 'system', 'passthru', 'popen', 'eval'])
    ->ignoring('OffsetSmoker\LaravelKit\Tests');

arch('every review rule is a final Rule')
    ->expect('OffsetSmoker\LaravelKit\Review\Rules')
    ->classes()
    ->toBeFinal()
    ->toImplement('OffsetSmoker\LaravelKit\Review\Rule');

arch('the plugin runtime has no Composer dependencies')
    ->expect('OffsetSmoker\LaravelKit')
    ->toOnlyUse(['OffsetSmoker\LaravelKit', 'RuntimeException', 'JsonException'])
    ->ignoring('OffsetSmoker\LaravelKit\Tests');
