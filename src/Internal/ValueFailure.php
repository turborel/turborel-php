<?php

declare(strict_types=1);

namespace Turborel\Internal;

use Turborel\TurborelException;

/**
 * The failures of a value the program gave that the protocol cannot carry:
 * data exceptions (SQLCODE -301), as turborel raises of the same value,
 * and a PHP value of no SQL type (SQLSTATE 07006, SQLCODE -921).
 *
 * @internal
 */
final class ValueFailure
{
    /** SQLCODE -301, a data exception, of SQLSTATE class 22. */
    public const int DATA = -301;

    /** SQLCODE -921, a dynamic SQL error, of SQLSTATE class 07. */
    public const int DYNAMIC = -921;

    /** SQLSTATE 22003, numeric value out of range. */
    public const string OUT_OF_RANGE = '22003';

    /** SQLSTATE 22021, character not in repertoire: a string not UTF-8. */
    public const string NOT_IN_REPERTOIRE = '22021';

    /** SQLSTATE 22032, invalid JSON text, and JSON with no text. */
    public const string INVALID_JSON = '22032';

    /** SQLSTATE 07006, restricted data type attribute violation. */
    public const string RESTRICTED = '07006';

    /** SQLSTATE 2200N, invalid XML content. */
    public const string INVALID_XML_CONTENT = '2200N';

    /** A number, or a field's value, out of its range: `$message` says which. */
    public static function outOfRange(string $message): TurborelException
    {
        return new TurborelException($message, self::OUT_OF_RANGE, self::DATA);
    }

    /** A string that is not UTF-8: `$message` says which. */
    public static function notInRepertoire(string $message): TurborelException
    {
        return new TurborelException($message, self::NOT_IN_REPERTOIRE, self::DATA);
    }

    /** JSON text that does not parse, or JSON that has no text: `$message` says why. */
    public static function invalidJson(string $message): TurborelException
    {
        return new TurborelException($message, self::INVALID_JSON, self::DATA);
    }

    /** Text that is not well-formed XML content: `$message` says why. */
    public static function invalidXmlContent(string $message): TurborelException
    {
        return new TurborelException($message, self::INVALID_XML_CONTENT, self::DATA);
    }

    /** A PHP value the protocol has no value for: `$message` says which. */
    public static function restricted(string $message): TurborelException
    {
        return new TurborelException($message, self::RESTRICTED, self::DYNAMIC);
    }
}
