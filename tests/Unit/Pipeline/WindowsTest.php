<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Pipeline\Queued;
use Turborel\Internal\Pipeline\Running;
use Turborel\Pipeline\Pipeline;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * A pipeline's windows, with no server.
 */
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Pipeline')]
#[CoversClass(TurborelException::class)]
final class WindowsTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Pipelines]: requests are grouped into windows of at
     * most 64 KiB, in order, a request longer than a window alone in one,
     * every request in one window once; none queued, no window.
     *
     * @throws TurborelException never, the requests being well formed
     */
    #[Test]
    public function requestsAreWrittenAWindowAtATime(): void
    {
        $queued = [Queued::query('a'), Queued::query('VALUES 1'), Queued::query(str_repeat('x', intdiv(Pipeline::WINDOW, 2))), Queued::query(str_repeat('y', Pipeline::WINDOW)), Queued::query('z')];
        self::assertSame([3, 1, 1], array_map(\count(...), Running::windows($queued, false, [])));
        $all = implode('', array_merge(...array_map(static fn(array $w): array => array_column($w, 1), Running::windows($queued, true, []))));
        self::assertSame(implode('', array_map(static fn(Queued $q): string => $q->request(true, [])->encode(), $queued)), $all, 'every request, each once');
        self::assertSame([], Running::windows([], false, []));
    }
}
