<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Lobs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Lobs\Pieces;
use Turborel\Tests\Support\CodecTestCase;

/**
 * Where a character large object's octets are cut into Appends.
 */
#[CoversClass(Pieces::class)]
final class PiecesTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Large objects]: a cut is never within a UTF-8
     * character: backed off to a character's start within the octets, and
     * at their end leaving out a character split there, its rest yet to
     * come; a whole last character is kept; ASCII is cut where asked.
     */
    #[Test]
    public function aCutIsNeverWithinACharacter(): void
    {
        self::assertSame([4, 3, 5, 3, 6, 6, 0, 3], [Pieces::charCut('abcdef', 4), Pieces::charCut("abc\u{E9}x", 4), Pieces::charCut("abc\u{E9}x", 5),
            Pieces::charCut("abc\xC3", 9), Pieces::charCut("abc\u{20AC}", 9), Pieces::charCut("ab\u{1F600}", 6), Pieces::charCut("\u{1F600}", 2), Pieces::charCut('abc', 3)]);
    }
}
