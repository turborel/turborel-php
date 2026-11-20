<?php

declare(strict_types=1);

namespace Turborel\Internal;

use Turborel\TurborelException;

/**
 * The failures of a value the program gave that the protocol cannot carry:
 * data exceptions (SQLCODE -301), as turborel raises of the same value,
 * and a PHP value of no SQL type (SQLSTATE 22000, SQLCODE -301, as every
 * Turborel driver fails a value the protocol cannot carry).
 *
 * @internal
 */
final class ValueFailure
{
    /** SQLCODE -301, a data exception, of SQLSTATE class 22. */
    public const int DATA = -301;


    /** SQLSTATE 22003, numeric value out of range. */
    public const string OUT_OF_RANGE = '22003';

    /** SQLSTATE 22021, character not in repertoire: a string not UTF-8. */
    public const string NOT_IN_REPERTOIRE = '22021';

    /** SQLSTATE 22032, invalid JSON text, and JSON with no text. */
    public const string INVALID_JSON = '22032';

    /** SQLSTATE 22000, data exception, of a value the protocol cannot carry. */
    public const string RESTRICTED = '22000';

    /** SQLCODE -916, of a stored form that is damaged, as turborel's Code::Corrupt. */
    public const int CORRUPT_CODE = -916;

    /** SQLSTATE TB916, of a stored form that is damaged. */
    public const string CORRUPT = 'TB916';

    /** SQLCODE -900, of what is beyond turborel's limits, as its Code::Unsupported. */
    public const int UNSUPPORTED_CODE = -900;

    /** SQLSTATE 0A000, feature not supported: beyond turborel's limits. */
    public const string UNSUPPORTED = '0A000';

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

    /** A JSON value's octets damaged: `$why` says how. */
    public static function damagedJson(string $why): TurborelException
    {
        return new TurborelException("a JSON value's octets are damaged: {$why}", self::CORRUPT, self::CORRUPT_CODE);
    }

    /** A JSON item beyond turborel's limits: `$why` says which. */
    public static function beyondJson(string $why): TurborelException
    {
        return new TurborelException("the SQL/JSON item is beyond turborel's limits: {$why}", self::UNSUPPORTED, self::UNSUPPORTED_CODE);
    }

    /** Text that is not well-formed XML content: `$message` says why. */
    public static function invalidXmlContent(string $message): TurborelException
    {
        return new TurborelException($message, self::INVALID_XML_CONTENT, self::DATA);
    }

    /** A PHP value the protocol has no value for: `$message` says which. */
    public static function restricted(string $message): TurborelException
    {
        return new TurborelException($message, self::RESTRICTED, self::DATA);
    }
}
