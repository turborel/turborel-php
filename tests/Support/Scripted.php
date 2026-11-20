<?php

declare(strict_types=1);

namespace Turborel\Tests\Support;

/**
 * A stream wrapper of a server's replies written before, and the bytes the
 * client sends kept apart: so a test of a layer writes as much as it likes,
 * never blocked by a peer that does not read, and reads what the script
 * says (RULES.md R-TEST-1).
 */
final class Scripted
{
    /** The wrapper's protocol. */
    private const string PROTOCOL = 'turborel-scripted';

    /** @var array<string, array{string, string}> each script's replies left to read, and what was sent, by name */
    private static array $scripts = [];

    /** @var resource|null the context PHP gives an opened stream */
    public $context;

    /** The name of the script this stream plays. */
    private string $name = '';

    /**
     * A stream that reads `$replies` and keeps what is written, by `$name`.
     *
     * @return resource
     */
    public static function open(string $name, string $replies): mixed
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        self::$scripts[$name] = [$replies, ''];
        $stream = fopen(self::PROTOCOL . "://{$name}", 'r+b');
        \assert(\is_resource($stream));

        return $stream;
    }

    /** What the stream named `$name` was sent. */
    public static function sent(string $name): string
    {
        return self::$scripts[$name][1] ?? '';
    }

    /** Opens the script named in `$path`. */
    public function stream_open(string $path): bool
    {
        $this->name = (string) parse_url($path, \PHP_URL_HOST);

        return isset(self::$scripts[$this->name]);
    }

    /** At most `$count` bytes of the replies left. */
    public function stream_read(int $count): string
    {
        $left = self::$scripts[$this->name][0] ?? '';
        self::$scripts[$this->name][0] = substr($left, $count);

        return substr($left, 0, $count);
    }

    /** Keeps `$bytes` as sent. */
    public function stream_write(string $bytes): int
    {
        self::$scripts[$this->name][1] = (self::$scripts[$this->name][1] ?? '') . $bytes;

        return \strlen($bytes);
    }

    /** Whether every reply was read. */
    public function stream_eof(): bool
    {
        return (self::$scripts[$this->name][0] ?? '') === '';
    }

    /** Takes a flush. */
    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * Takes an option, as a timeout: none.
     */
    public function stream_set_option(int $option, int $first, ?int $second): bool
    {
        return false;
    }
}
