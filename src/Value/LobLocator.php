<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * A large object reached by its locator: its kind, the locator, and its
 * lengths in octets and characters [PROTOCOL Values]. The locator lasts
 * until its transaction ends; Lob requests read it.
 */
final readonly class LobLocator implements SqlValue
{
    /** The large object of kind `$kind` at `$locator`, `$octets` long, `$characters` characters. */
    public function __construct(
        /** Whether it holds characters or octets. */
        public LobKind $kind,
        /** Its locator, a u32. */
        public int $locator,
        /** Its length in octets, a u64: an int, or past PHP_INT_MAX its digits. */
        public int|string $octets,
        /** Its length in characters, a u64, as the octets'. */
        public int|string $characters,
    ) {}
}
