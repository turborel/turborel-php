<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;
use Turborel\Type\XmlPrimary;
use Turborel\Type\XmlSchemaName;
use Turborel\Type\XmlSecondary;
use Turborel\Type\XmlType;

/**
 * An XML type's modifiers: primary (0 DOCUMENT, 1 CONTENT, 2 SEQUENCE),
 * secondary (0 UNTYPED, 1 ANY, 2 XMLSCHEMA), and of XMLSCHEMA the schema's
 * schema and name, then its namespace and its element, each 0 for none or
 * 1 and a string [PROTOCOL Data types]. SEQUENCE is UNTYPED alone, as
 * turborel reads it.
 *
 * @internal
 */
final class XmlTypes
{
    /** The byte before a namespace or element given. */
    private const int PRESENT = 1;

    /** The byte of a namespace or element not given. */
    private const int ABSENT = 0;

    /**
     * The XML type's modifiers `$reader` reads next.
     *
     * @throws TurborelException SQLSTATE 08006 if they are malformed
     */
    public static function read(Reader $reader): XmlType
    {
        $primary = XmlPrimary::tryFrom($reader->u8());
        $secondary = XmlSecondary::tryFrom($reader->u8()) ?? throw $reader->malformed('an XML type\'s secondary modifier is none');
        $schema = $secondary === XmlSecondary::XmlSchema ? self::schema($reader) : null;
        if ($primary === null || ($primary === XmlPrimary::Sequence && $secondary !== XmlSecondary::Untyped)) {
            throw $reader->malformed('an XML type\'s primary modifier is none');
        }

        return new XmlType($primary, $secondary, $schema);
    }

    /**
     * Writes an XML type's modifiers.
     *
     * @throws TurborelException SQLSTATE 22021 if a name is not UTF-8
     */
    public static function write(Writer $writer, XmlType $type): Writer
    {
        $writer->u8($type->primary->value)->u8($type->secondary->value);
        if ($type->schema === null) {
            return $writer;
        }
        $writer->string($type->schema->schema)->string($type->schema->name);
        foreach ([$type->schema->namespace, $type->schema->element] as $part) {
            if ($part === null) {
                $writer->u8(self::ABSENT);
            } else {
                $writer->u8(self::PRESENT)->string($part);
            }
        }

        return $writer;
    }

    /**
     * What a type of XMLSCHEMA indicates.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function schema(Reader $reader): XmlSchemaName
    {
        [$schema, $name] = [$reader->string(), $reader->string()];
        $namespace = self::part($reader);

        return new XmlSchemaName($schema, $name, $namespace, self::part($reader));
    }

    /**
     * A namespace or an element: 0 for none, or 1 and a string.
     *
     * @throws TurborelException SQLSTATE 08006 if it is marked otherwise
     */
    private static function part(Reader $reader): ?string
    {
        return match ($reader->u8()) {
            self::ABSENT => null,
            self::PRESENT => $reader->string(),
            default => throw $reader->malformed('an XML type\'s namespace or element is marked neither 0 nor 1'),
        };
    }
}
