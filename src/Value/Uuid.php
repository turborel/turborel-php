<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * A UUID: its 16 octets (turborel RULES.md D184), shown as its text of 32
 * hexadecimal digits in groups of 8, 4, 4, 4 and 12.
 */
final readonly class Uuid implements SqlValue
{
    /** The octets of a UUID. */
    public const int OCTETS = 16;

    /** A UUID's text, in either case. */
    private const string TEXT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Where the text's hyphens go, counting hexadecimal digits. */
    private const array GROUPS = [8, 4, 4, 4, 12];

    /**
     * The UUID of `$octets`.
     *
     * @throws TurborelException SQLSTATE 22003 unless there are 16
     */
    public function __construct(
        /** Its 16 octets. */
        public string $octets,
    ) {
        if (\strlen($octets) !== self::OCTETS) {
            throw ValueFailure::outOfRange('a UUID is 16 octets, not ' . \strlen($octets));
        }
    }

    /**
     * The UUID whose text is `$text`, as `919108f7-52d1-4320-9bac-f847db4148a8`.
     *
     * @throws TurborelException SQLSTATE 22003 if it is no UUID's text
     */
    public static function fromText(string $text): self
    {
        $octets = preg_match(self::TEXT, $text) === 1 ? hex2bin(str_replace('-', '', $text)) : false;
        if ($octets === false) {
            throw ValueFailure::outOfRange("'{$text}' is not a UUID");
        }

        return new self($octets);
    }

    /** Its text: lower case hexadecimal, hyphenated. */
    public function __toString(): string
    {
        $hex = bin2hex($this->octets);
        $groups = [];
        $at = 0;
        foreach (self::GROUPS as $length) {
            $groups[] = substr($hex, $at, $length);
            $at += $length;
        }

        return implode('-', $groups);
    }
}
