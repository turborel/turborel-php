<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Tail;
use Turborel\Internal\Codec\Writer;
use Turborel\Turborel;
use Turborel\TurborelException;

/**
 * Hello 'H': the first message of a connection that logs in: the protocol's version, the user, a mechanism and its first bytes, the capabilities asked for, and the connection's attributes, tail tag 1 [PROTOCOL Hello 'H'].
 *
 * @internal
 */
final readonly class Hello implements Request
{
    /**
     * The request of its fields.
     *
     * @param list<string>                $capabilities
     * @param list<array{string, string}> $attributes
     */
    public function __construct(
        /** Who the client says it is. */
        public string $user,
        /** The mechanism of authentication, by its name. */
        public string $mechanism,
        #[\SensitiveParameter]
        /** The mechanism's first bytes. */
        public string $initial,
        /** The capabilities asked for, by name. */
        public array $capabilities,
        /** The connection's attributes, each a name and a value; its tail. */
        public array $attributes = [],
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        $writer = (new Writer())->u32(Turborel::PROTOCOL_VERSION)->string($this->user)->string($this->mechanism)
            ->bytes($this->initial)->strings($this->capabilities);
        Tail::writeAttributes($writer, $this->attributes);

        return Frames::frame(Kind::HELLO, $writer->bytesWritten());
    }

    /**
     * Its fields as var_dump() shows them, the mechanism's first bytes left out, as a password's may be (RULES.md R-SAFE-4).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['user' => $this->user, 'mechanism' => $this->mechanism, 'initial' => '(secret)', 'capabilities' => $this->capabilities];
    }
}
