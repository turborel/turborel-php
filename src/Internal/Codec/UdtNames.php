<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;
use Turborel\Type\StructuredType;
use Turborel\Type\UdtName;

/**
 * A user-defined type's names, as a structured type and a reference type
 * write them: its schema and name, then its proper supertypes', their
 * count first [PROTOCOL Data types].
 *
 * @internal
 */
final class UdtNames
{
    /**
     * A schema and a name.
     *
     * @throws TurborelException SQLSTATE 08006 if they are malformed
     */
    public static function read(Reader $reader): UdtName
    {
        return new UdtName($reader->string(), $reader->string());
    }

    /**
     * A structured type's names: its own, then its proper supertypes'.
     *
     * @throws TurborelException SQLSTATE 08006 if they are malformed
     */
    public static function structured(Reader $reader): StructuredType
    {
        $name = self::read($reader);
        $supertypes = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $supertypes[] = self::read($reader);
        }

        return new StructuredType($name, $supertypes);
    }

    /**
     * Writes a structured type's names.
     *
     * @throws TurborelException SQLSTATE 22021 if a name is not UTF-8
     */
    public static function write(Writer $writer, StructuredType $type): Writer
    {
        $writer->string($type->name->schema)->string($type->name->name)->u32(\count($type->supertypes));
        foreach ($type->supertypes as $supertype) {
            $writer->string($supertype->schema)->string($supertype->name);
        }

        return $writer;
    }
}
