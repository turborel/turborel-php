<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * A message's tail: the fields version 1 gained after a message's first
 * form, each a tag and its field, in increasing order of tag, to the end
 * of the body [PROTOCOL The tail]. A field is written only where it says
 * something, so a message with an empty tail is its first form exactly.
 *
 * @internal
 */
final class Tail
{
    /** Attributes, on Hello, Query and Execute. */
    public const int ATTRIBUTES = 1;

    /** Multi, on Query: its text is several statements. */
    public const int MULTI = 2;

    /** Cursor, on Execute, Fetch and Close. */
    public const int CURSOR = 3;

    /** Position, on Complete: the commit's log position. */
    public const int POSITION = 4;

    /** More, on Complete: another statement's replies follow. */
    public const int MORE = 5;

    /** Rows, on Complete: the rows the statement counted. */
    public const int ROWS = 6;

    /** Severity, the first of a failure's or a notice's diagnostics. */
    public const int SEVERITY = 7;

    /** Constraint, the last of a failure's or a notice's diagnostics. */
    public const int CONSTRAINT = 15;

    /** Generated, on Complete: the identity values an INSERT generated. */
    public const int GENERATED = 16;

    /** Conditions, on Complete: the conditions the statement raised. */
    public const int CONDITIONS = 17;

    /** Time, on Complete: the server's time for the statement. */
    public const int TIME = 18;

    /** [DESIGN] The most attributes a message takes [PROTOCOL The tail]. */
    public const int MAX_ATTRIBUTES = 32;

    /** [DESIGN] The longest name of an attribute, in bytes. */
    public const int MAX_ATTRIBUTE_NAME = 64;

    /** [DESIGN] The longest value of an attribute, in bytes. */
    public const int MAX_ATTRIBUTE_VALUE = 1024;

    /** The last tag read. */
    private int $last = 0;

    /**
     * The next tag of the tail, of those `$taken`; none at the body's end.
     *
     * @param list<int> $taken
     *
     * @throws TurborelException SQLSTATE 08006 if it is not taken, or not past the last
     */
    public function next(Reader $reader, array $taken): ?int
    {
        if ($reader->atEnd()) {
            return null;
        }
        $tag = $reader->u8();
        if ($tag <= $this->last || !\in_array($tag, $taken, true)) {
            throw $reader->malformed("its tail has tag {$tag} out of place");
        }
        $this->last = $tag;

        return $tag;
    }

    /**
     * Writes tag 1 and `$attributes`, unless there are none.
     *
     * @param list<array{string, string}> $attributes
     *
     * @throws TurborelException SQLSTATE 08006 if they are past their bounds, the connection kept, as the server would fail it; 22021 if one is not UTF-8
     */
    public static function writeAttributes(Writer $writer, array $attributes): void
    {
        if ($attributes === []) {
            return;
        }
        if (\count($attributes) > self::MAX_ATTRIBUTES) {
            throw ConnectionFailure::because('a message takes at most ' . self::MAX_ATTRIBUTES . ' attributes');
        }
        $writer->u8(self::ATTRIBUTES)->u32(\count($attributes));
        foreach ($attributes as [$name, $value]) {
            if (!self::fits($name, $value)) {
                throw ConnectionFailure::because("the attribute '{$name}' is past its bounds: a name of 1 to 64 bytes, a value of at most 1024");
            }
            $writer->string($name)->string($value);
        }
    }

    /** Whether an attribute's `$name` and `$value` are within their bounds. */
    private static function fits(string $name, string $value): bool
    {
        return $name !== '' && \strlen($name) <= self::MAX_ATTRIBUTE_NAME && \strlen($value) <= self::MAX_ATTRIBUTE_VALUE;
    }
}
