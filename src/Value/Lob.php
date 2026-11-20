<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\Codec\Utf8;
use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * A large object held whole: its kind, then its octets [PROTOCOL Values].
 * A server sends one this way up to SERVER.LOB_INLINE_BYTES, and a client
 * always sends one whole, or as a locator it made.
 */
final readonly class Lob implements SqlValue
{
    /**
     * The large object of kind `$kind` whose octets are `$octets`.
     *
     * @throws TurborelException SQLSTATE 22021 if a character one's octets are not UTF-8
     */
    public function __construct(
        /** Whether it holds characters or octets. */
        public LobKind $kind,
        /** Its octets: of a CLOB, UTF-8. */
        public string $octets,
    ) {
        if ($kind === LobKind::Character && !Utf8::valid($octets)) {
            throw ValueFailure::notInRepertoire("a character large object's octets are not UTF-8");
        }
    }
}
