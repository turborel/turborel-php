<?php

declare(strict_types=1);

namespace Turborel\Type;

use Turborel\Internal\Codec\Written;

/**
 * A column of a query's rows: its name, if it has one, its type, whether
 * it is NOT NULL [PROTOCOL Composite fields], and the type it was declared
 * with, granted `declared-types` [PROTOCOL Declared types].
 */
final readonly class Column
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its name, if it is named. */
        public ?string $name,
        /** Its type, as its values' tag tells it. */
        public DataType $type,
        /** Whether it is NOT NULL. */
        public bool $notNull,
        /** The type it was declared with, granted `declared-types`. */
        public ?Declared $declared = null,
    ) {}

    /** Its type as SQL writes it: as it was declared, if told, else as its type says, as turborel-client's `type_written`. */
    public function typeWritten(): string
    {
        return $this->declared->written ?? Written::of($this->type);
    }

    /** It, with the type it was declared with. */
    public function withDeclared(Declared $declared): self
    {
        return new self($this->name, $this->type, $this->notNull, $declared);
    }
}
