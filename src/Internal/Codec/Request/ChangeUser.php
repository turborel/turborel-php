<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * ChangeUser 'U', with `change-user`: the connection authenticated again, as Hello does [PROTOCOL ChangeUser 'U'].
 *
 * @internal
 */
final readonly class ChangeUser implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        /** Who the client says it is now. */
        public string $user,
        /** The mechanism of authentication, by its name. */
        public string $mechanism,
        #[\SensitiveParameter]
        /** The mechanism's first bytes. */
        public string $initial,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        $writer = (new Writer())->string($this->user)->string($this->mechanism)->bytes($this->initial);

        return Frames::frame(Kind::CHANGE_USER, $writer->bytesWritten());
    }

    /**
     * Its fields as var_dump() shows them, the mechanism's first bytes left out, as a password's may be (RULES.md R-SAFE-4).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['user' => $this->user, 'mechanism' => $this->mechanism, 'initial' => '(secret)'];
    }
}
