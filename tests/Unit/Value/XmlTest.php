<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Quietly;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Value\Xml;

/**
 * An XML value's nodes, parsed from its text.
 */
#[CoversClass(Xml::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(Quietly::class)]
#[CoversClass(TurborelException::class)]
final class XmlTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT XML values]: an XML value's text is parsed as
     * content for its nodes, in order, text and comments among them; empty,
     * it has none; text that is not well-formed content fails with
     * SQLSTATE 2200N.
     *
     * @throws TurborelException never from the content well formed
     */
    #[Test]
    public function anXmlValueIsParsedForItsNodes(): void
    {
        $nodes = (new Xml('<a x="1">t</a>tail<!--c-->'))->nodes();
        self::assertSame(['a', '#text', '#comment'], array_map(static fn(\DOMNode $node): string => $node->nodeName, $nodes));
        self::assertSame([], (new Xml(''))->nodes());
        self::assertFailure('2200N', -301, static fn() => (new Xml('<a>'))->nodes());
    }
}
