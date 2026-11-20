<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * A value of a plug-in's type: its type's name, its text and its bytes
 * [PROTOCOL Values]. The driver has no plug-in's types, so it keeps all
 * three as they came, shows the text, and sends one back unchanged.
 */
final readonly class PluginValue implements SqlValue
{
    /** The value of the plug-in type `$type`, its text `$text` and its bytes `$bytes`. */
    public function __construct(
        /** Its type's name. */
        public string $type,
        /** Its text, as the server shows it. */
        public string $text,
        /** Its bytes, as the plug-in writes it. */
        public string $bytes,
    ) {}
}
