<?php

declare(strict_types=1);

use App\Greeter;

require __DIR__.'/../app/Greeter.php';

$greeting = (new Greeter)->greet('Ada');

if ($greeting !== 'Hello, Ada') {
    fwrite(STDERR, "FAILED: expected 'Hello, Ada', got '{$greeting}'\n");
    exit(1);
}

echo "1 test passed\n";
