<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * An XML value, as its serialization as CONTENT [PROTOCOL Values].
 */
final readonly class Xml implements SqlValue
{
    /** Makes it of `$text`. */
    public function __construct(
        /** Its serialization, UTF-8. */
        public string $text,
    ) {}
}
