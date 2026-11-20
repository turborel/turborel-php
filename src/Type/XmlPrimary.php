<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * An XML type's primary modifier [PROTOCOL Data types].
 */
enum XmlPrimary: int
{
    /** XML(DOCUMENT). */
    case Document = 0;

    /** XML(CONTENT). */
    case Content = 1;

    /** XML(SEQUENCE). */
    case Sequence = 2;
}
