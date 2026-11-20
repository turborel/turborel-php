<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\Misuse;
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
    private const string TEXT = '/^[0-9a-f]{4}(?:-?[0-9a-f]{4}){6}-?[0-9a-f]{4}$/i';

    /** Where the text's hyphens go, counting hexadecimal digits. */
    private const array GROUPS = [8, 4, 4, 4, 12];

    /**
     * The UUID of `$octets`.
     *
     * @throws TurborelException SQLSTATE TB910 unless there are 16
     */
    public function __construct(
        /** Its 16 octets. */
        public string $octets,
    ) {
        if (\strlen($octets) !== self::OCTETS) {
            throw Misuse::of('a UUID is 16 octets, not ' . \strlen($octets));
        }
    }

    /**
     * The UUID whose text is `$text`, as turborel's CAST reads it: 32
     * hexadecimal digits in either case, as
     * `919108f7-52d1-4320-9bac-f847db4148a8`, a hyphen allowed after each
     * group of four but the last, its white space trimmed, braced or not.
     *
     * @throws TurborelException SQLSTATE 22018 if it is no UUID's text
     */
    public static function fromText(string $text): self
    {
        $trimmed = preg_replace('/^\p{White_Space}+|\p{White_Space}+$/u', '', $text) ?? $text;
        $bare = preg_match('/^\{(.*)\}$/s', $trimmed, $braced) === 1 ? $braced[1] : $trimmed;
        $octets = preg_match(self::TEXT, $bare) === 1 ? hex2bin(str_replace('-', '', $bare)) : false;
        if ($octets === false) {
            throw ValueFailure::invalidForCast("'{$text}' is not a UUID's text");
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
