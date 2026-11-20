<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Json;

/**
 * A JSON object's members in the order written, those of one key all, as
 * the stored form keeps them, where a PHP array would keep one of a key.
 *
 * @internal
 */
final readonly class JsonObject
{
    /**
     * The object of `$members`.
     *
     * @param list<array{string, mixed}> $members
     */
    public function __construct(
        /** @var list<array{string, mixed}> $members each key and value, in order */
        public array $members,
    ) {}
}
