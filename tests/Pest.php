<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Tests\Support\FixtureRepository;

/*
 * Feature tests run the real scripts against a fresh copy of the fixture
 * project in a temporary git repository. Each copy is removed afterwards.
 */
afterEach(function (): void {
    FixtureRepository::cleanUp();
});
