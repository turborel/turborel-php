<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Turborel\Turborel;

/**
 * The package's root: the version it tells, held to composer.json's.
 */
#[CoversClass(Turborel::class)]
final class TurborelTest extends TestCase
{
    /**
     * Verifies: [DESIGN] D5 D14: the version is written once, in
     * composer.json, and Turborel::VERSION is held to it.
     */
    #[Test]
    public function theVersionIsComposerJsons(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 16, \JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertSame($manifest['version'] ?? null, Turborel::VERSION);
    }
}
