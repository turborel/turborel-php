<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * What a type of XMLSCHEMA indicates: the XML schema's schema and name, then its namespace and element, each if given [PROTOCOL Data types].
 */
final readonly class XmlSchemaName
{
    /** Makes it of its parts. */
    public function __construct(
        /** The registered XML schema's SQL schema. */
        public string $schema,
        /** Its name. */
        public string $name,
        /** The namespace indicated, if any. */
        public ?string $namespace,
        /** The element indicated, if any. */
        public ?string $element,
    ) {}
}
