<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Session\KeyCache;
use Turborel\Tests\Support\CodecTestCase;

/**
 * The SCRAM key cache, as turborel-client's: found by salt, iterations
 * and a tag of the password, bounded, its entries forgotten on failure.
 */
#[CoversClass(KeyCache::class)]
final class KeyCacheTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT The SCRAM key cache]: an entry is found by the
     * salt, the iterations and the password's tag, so another password, salt
     * or count of iterations finds nothing; its lookups are counted; it
     * holds 64 entries at most, the oldest dropped first; an entry is
     * forgotten, or all cleared; a cache shares nothing with another, and
     * never shows its keys.
     */
    #[Test]
    public function entriesAreFoundByTheirSaltIterationsAndPasswordTag(): void
    {
        $cache = new KeyCache();
        $keys = $cache->derived('pw', 'salt', 2);
        self::assertSame(KeyCache::derive('pw', 'salt', 2), $keys);
        self::assertSame($keys, $cache->derived('pw', 'salt', 2));
        $cache->derived('other', 'salt', 2);
        $cache->derived('pw', 'pepper', 2);
        $cache->derived('pw', 'salt', 3);
        self::assertSame([[1, 4], 4], [$cache->lookups(), $cache->count()]);
        for ($salt = 0; $salt < KeyCache::ENTRIES; $salt++) {
            $cache->derived('pw', "s{$salt}", 1);
        }
        self::assertSame(KeyCache::ENTRIES, $cache->count());
        [, $misses] = $cache->lookups();
        $cache->derived('pw', 'salt', 2);
        self::assertSame($misses + 1, $cache->lookups()[1], 'the oldest dropped: a miss');
        $cache->forget('pw', 's63', 1);
        self::assertSame(KeyCache::ENTRIES - 1, $cache->count());
        self::assertSame(0, (new KeyCache())->count());
        self::assertStringNotContainsString(bin2hex($keys[0]), print_r($cache->__debugInfo(), true));
        $cache->clear();
        self::assertSame(0, $cache->count());
    }
}
