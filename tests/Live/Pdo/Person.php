<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Pdo;

/** A row fetched as an object of a class of the program's. */
final class Person
{
    /** The NAME column. */
    public string $NAME = '';

    /** What the constructor was told. */
    public string $kind = '';

    /** The person, of the kind `$kind`. */
    public function __construct(string $kind)
    {
        $this->kind = $kind;
    }
}
