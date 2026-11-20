<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * XML, tag 26: its primary and secondary modifiers, and of XMLSCHEMA what
 * it indicates [PROTOCOL Data types]. SEQUENCE is UNTYPED alone.
 */
final readonly class XmlType implements DataType
{
    /** The XML type of `$primary` and `$secondary`, and of XMLSCHEMA `$schema`. */
    public function __construct(
        /** Its primary modifier. */
        public XmlPrimary $primary,
        /** Its secondary modifier. */
        public XmlSecondary $secondary = XmlSecondary::Untyped,
        /** Of XMLSCHEMA, what it indicates; else none. */
        public ?XmlSchemaName $schema = null,
    ) {}
}
