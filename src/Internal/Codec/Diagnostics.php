<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;

/**
 * What a failure or a notice says beyond its SQLSTATE and message, tail
 * tags 7 to 15 [PROTOCOL Errors and notices]: each none where it says
 * nothing of it.
 *
 * @internal
 */
final readonly class Diagnostics
{
    /** The severity of a failure that leaves the connection, as turborel's ERROR. */
    public const string ERROR = 'ERROR';

    /** The severity of a failure that ends the connection, as turborel's FATAL. */
    public const string FATAL = 'FATAL';

    /** The fields, by tag from SEVERITY, as `$fields` holds them. */
    private const array NAMES = ['severity', 'detail', 'hint', 'context', 'schema', 'table', 'column', 'dataType', 'constraint'];

    /**
     * The diagnostics `$fields` gives, by name.
     *
     * @param array<string, string> $fields
     */
    public function __construct(
        /** @var array<string, string> $fields severity, detail, hint, context, schema, table, column, dataType and constraint, each that is known */
        public array $fields,
    ) {}

    /**
     * The diagnostics of a tail of diagnostics alone, to the body's end: none if it is empty.
     *
     * @throws TurborelException SQLSTATE 08006 if a tag is out of place, or a field malformed
     */
    public static function read(Reader $reader): ?self
    {
        $tail = new Tail();
        $fields = [];
        $taken = range(Tail::SEVERITY, Tail::CONSTRAINT);
        while (($tag = $tail->next($reader, $taken)) !== null) {
            $fields[self::NAMES[$tag - Tail::SEVERITY] ?? ''] = $reader->string();
        }

        return $fields === [] ? null : new self($fields);
    }

    /** The field `$name`, one of NAMES, if it is known. */
    public function get(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }
}
