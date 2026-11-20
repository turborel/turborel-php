<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * An XML type's secondary modifier [PROTOCOL Data types].
 */
enum XmlSecondary: int
{
    /** UNTYPED. */
    case Untyped = 0;

    /** ANY. */
    case Any = 1;

    /** XMLSCHEMA, which names the schema it is validated by. */
    case XmlSchema = 2;
}
