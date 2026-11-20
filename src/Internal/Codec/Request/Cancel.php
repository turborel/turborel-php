<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Reader;
use Turborel\Internal\Misuse;
use Turborel\TurborelException;

/**
 * Cancel 'Z': the first and only message of a connection that cancels another's statement, by the key that connection's Ready gave [PROTOCOL Cancel 'Z'].
 *
 * @internal
 */
final readonly class Cancel implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        #[\SensitiveParameter]
        /** The 16 bytes of the key. */
        public string $key,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE TB910 if the key is not 16 bytes
     */
    public function encode(): string
    {
        if (\strlen($this->key) !== Reader::KEY) {
            throw Misuse::of('a cancel key is 16 bytes');
        }

        return Frames::frame(Kind::CANCEL, $this->key);
    }

    /**
     * Its fields as var_dump() shows them, the key left out, as a secret is (RULES.md R-SAFE-4).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['key' => '(secret)'];
    }
}
