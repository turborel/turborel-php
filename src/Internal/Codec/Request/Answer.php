<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Answer 'A': the client's answer to a Challenge [PROTOCOL Answer 'A'].
 *
 * @internal
 */
final readonly class Answer implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        #[\SensitiveParameter]
        /** The answer's bytes. */
        public string $answer,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if the answer is longer than a u32 counts
     */
    public function encode(): string
    {
        return Frames::frame(Kind::ANSWER, (new Writer())->bytes($this->answer)->bytesWritten());
    }

    /**
     * Its fields as var_dump() shows them, the answer left out, as a proof is a secret (RULES.md R-SAFE-4).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['answer' => '(secret)'];
    }
}
