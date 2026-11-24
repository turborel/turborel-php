<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * The mode of a procedure's parameter whose argument holds a CALL's `?`,
 * coded as ISO/IEC 9075-2:2023's Table 33 codes it [PROTOCOL Prepared 'P']:
 * an execution gives a value for an IN or INOUT one, and gives back the
 * value of an OUT or INOUT one. A `?` of any other statement is IN.
 */
enum ParameterMode: int
{
    /** An input parameter: its value is given. */
    case In = 1;

    /** An input and output parameter: its value is given, and given back. */
    case InOut = 2;

    /** An output parameter: its value is given back. */
    case Out = 4;

    /** Whether an execution gives a value for it. */
    public function takesValue(): bool
    {
        return $this !== self::Out;
    }

    /** Whether an execution gives its value back. */
    public function givesValue(): bool
    {
        return $this !== self::In;
    }
}
