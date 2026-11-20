<?php

declare(strict_types=1);

namespace Turborel\Internal\Lobs;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * The stream wrapper that gives a large object's reader to PHP's file
 * functions, `fread` and `stream_get_contents` among them, read only. A
 * failure to read is thrown from the function that read.
 *
 * @internal
 */
final class LobStream
{
    /** The wrapper's protocol. */
    private const string PROTOCOL = 'turborel-lob';

    /** @var resource|null the context PHP gives an opened stream */
    public $context;

    /** The reader the stream reads. */
    private ?Reader $reader = null;

    /**
     * A stream of `$reader`, read only.
     *
     * @return resource
     *
     * @throws TurborelException SQLSTATE 08006 if PHP cannot open it
     */
    public static function open(Reader $reader): mixed
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $stream = fopen(self::PROTOCOL . '://lob', 'rb', false, stream_context_create([self::PROTOCOL => ['reader' => $reader]]));
        if (!\is_resource($stream)) {
            throw ConnectionFailure::because("cannot open a large object's stream");
        }

        return $stream;
    }

    /** Opens the stream of the reader its context holds, read only. */
    public function stream_open(string $path, string $mode): bool
    {
        $mine = \is_resource($this->context) ? (stream_context_get_options($this->context)[self::PROTOCOL] ?? null) : null;
        $reader = \is_array($mine) ? ($mine['reader'] ?? null) : null;
        if (!$reader instanceof Reader || !str_starts_with($mode, 'r')) {
            return false;
        }
        $this->reader = $reader;

        return true;
    }

    /**
     * At most `$count` octets more.
     *
     * @throws TurborelException as the reader does
     */
    public function stream_read(int $count): string
    {
        return $this->reader?->read($count) ?? '';
    }

    /** Whether every octet has been read. */
    public function stream_eof(): bool
    {
        return $this->reader?->ended() ?? true;
    }

    /**
     * What `fstat` gives of the stream: nothing known.
     *
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
