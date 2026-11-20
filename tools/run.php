<?php

declare(strict_types=1);

// The project's own checks, which no standard tool makes (RULES.md D4,
// R-PROC-5): `composer check` runs the source checks, `composer coverage`
// the coverage floor. Printing and the exit status are a tool's, never the
// library's (R-ERR-1).

use Turborel\Tools\Check;

require __DIR__ . '/../vendor/autoload.php';

exit(Check::main(\dirname(__DIR__), \array_slice($argv ?? [], 1)));
