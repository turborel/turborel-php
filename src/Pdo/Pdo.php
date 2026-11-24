<?php

declare(strict_types=1);

namespace Turborel\Pdo;

/**
 * The constants of the PDO-style API (RULES.md D2), each of `\PDO`'s
 * value, so a program that names `\PDO::FETCH_ASSOC` names the same; they
 * are here too, as PHP may be built without the PDO extension.
 */
final class Pdo
{
    /** A parameter's type: null. */
    public const int PARAM_NULL = 0;

    /** A parameter's type: an integer. */
    public const int PARAM_INT = 1;

    /** A parameter's type: a string. */
    public const int PARAM_STR = 2;

    /** A parameter's type: a large object, a string or a stream. */
    public const int PARAM_LOB = 3;

    /** A parameter's type: a truth value. */
    public const int PARAM_BOOL = 5;

    /** A flag of a parameter's type: of a CALL's INOUT or OUT parameter, its value written back to the variable bound. */
    public const int PARAM_INPUT_OUTPUT = 0x8000_0000;

    /** A row as an array by column name. */
    public const int FETCH_ASSOC = 2;

    /** A row as a list by column number, from 0. */
    public const int FETCH_NUM = 3;

    /** A row as an array by column name and by number. */
    public const int FETCH_BOTH = 4;

    /** A row as a \stdClass, a property for each column. */
    public const int FETCH_OBJ = 5;

    /** One column's value of each row. */
    public const int FETCH_COLUMN = 7;

    /** A row as an object of a class named, each property it declares of a column set before its constructor runs. */
    public const int FETCH_CLASS = 8;

    /** The connection's attribute: whether each statement is its own transaction. */
    public const int ATTR_AUTOCOMMIT = 0;

    /** The connection's attribute: how failures are told, always by exception. */
    public const int ATTR_ERRMODE = 3;

    /** The connection's attribute: the server's product and version. */
    public const int ATTR_SERVER_VERSION = 4;

    /** The connection's attribute: the driver's version. */
    public const int ATTR_CLIENT_VERSION = 5;

    /** The connection's attribute: the driver's name, `turborel`. */
    public const int ATTR_DRIVER_NAME = 16;

    /** The connection's attribute: the fetch mode statements take unless told. */
    public const int ATTR_DEFAULT_FETCH_MODE = 19;

    /** How failures are told: by exception, the one way this API tells them. */
    public const int ERRMODE_EXCEPTION = 2;

    /** The cursor's orientation of a fetch: the next row, the one this API reads. */
    public const int FETCH_ORI_NEXT = 0;
}
