<?php

declare(strict_types=1);

namespace Turborel\Internal\Agent;

/**
 * A connection statement, as turborel's parser reads one (ISO/IEC
 * 9075:1992 §15): CONNECT TO a server AS a name USER a user, or TO
 * DEFAULT; SET CONNECTION a name, or DEFAULT; DISCONNECT a name, DEFAULT,
 * ALL or CURRENT. Each name the text of its character string literal,
 * none where the statement names none; a value that is no such literal
 * kept as it was written, `$literal` false.
 *
 * @internal
 */
final readonly class Statement
{
    /** CONNECT TO a server. */
    public const int CONNECT = 0;

    /** CONNECT TO DEFAULT. */
    public const int CONNECT_DEFAULT = 1;

    /** SET CONNECTION. */
    public const int SET = 2;

    /** DISCONNECT. */
    public const int DISCONNECT = 3;

    /**
     * The statement of its parts.
     *
     * @param array{server?: Value, name?: Value, user?: Value} $values
     */
    public function __construct(
        /** Which statement it is. */
        public int $kind,
        /** @var array{server?: Value, name?: Value, user?: Value} $values the values it names */
        public array $values = [],
        /** Of DISCONNECT, ALL, CURRENT or DEFAULT if it names one; of SET CONNECTION, DEFAULT. */
        public ?string $object = null,
    ) {}
}
