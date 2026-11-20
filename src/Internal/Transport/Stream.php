<?php

declare(strict_types=1);

namespace Turborel\Internal\Transport;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\TurborelException;

/**
 * A connection's stream to its server, TCP, TLS over TCP, a Unix socket or
 * a named pipe, written whole and read exactly, each wait bounded as its
 * limit says [CLIENT Timeouts]. A failure of the stream, or a wait run
 * out, loses the connection (SQLSTATE 08006), saying why.
 *
 * @internal
 */
final class Stream
{
    /** [DESIGN] The longest wait a stream takes, in seconds, standing for none: PHP's streams always have one. */
    public const int UNBOUNDED = 2_147_483_647;

    /** The bytes read at a time, so a body is held as it arrives, never as its length says. */
    private const int CHUNK = 65_536;

    /** The microseconds of a second. */
    private const int MICROS = 1_000_000;

    /**
     * The stream of `$resource`, a named pipe if `$pipe`, which PHP cannot bound once open.
     *
     * @param resource $resource
     */
    public function __construct(
        /** @var resource $resource PHP's stream */
        private readonly mixed $resource,
        /** Whether it is a named pipe, whose reads and writes are not bounded once it is open [CLIENT Timeouts]. */
        private readonly bool $pipe = false,
    ) {}

    /**
     * Bounds each read and write to `$within` seconds, none for no limit; a named pipe is left as it is.
     *
     * @throws TurborelException SQLSTATE 08006 if the stream cannot be bounded
     */
    public function bound(?float $within): void
    {
        if ($this->pipe) {
            return;
        }
        $seconds = $within === null ? self::UNBOUNDED : (int) floor($within);
        $micros = $within === null ? 0 : (int) round(($within - floor($within)) * self::MICROS);
        [$set] = Quietly::call(fn(): bool => stream_set_timeout($this->resource, $seconds, $micros));
        if ($set !== true) {
            throw ConnectionFailure::because('cannot bound how long the connection waits');
        }
    }

    /**
     * Writes `$bytes` whole.
     *
     * @throws TurborelException SQLSTATE 08006 if the stream fails, or the wait runs out
     */
    public function write(string $bytes): void
    {
        for ($at = 0; $at < \strlen($bytes); $at += $written) {
            [$written, $warning] = Quietly::call(fn(): int|false => fwrite($this->resource, substr($bytes, $at)));
            if (!\is_int($written) || $written === 0) {
                throw $this->failed('send', $warning);
            }
        }
        Quietly::call(fn(): bool => fflush($this->resource));
    }

    /**
     * Reads exactly `$count` bytes, as they arrive.
     *
     * @throws TurborelException SQLSTATE 08006 if the stream fails or ends first, or the wait runs out
     */
    public function read(int $count): string
    {
        $bytes = '';
        while (\strlen($bytes) < $count) {
            $want = min(self::CHUNK, $count - \strlen($bytes));
            [$chunk, $warning] = Quietly::call(fn(): string|false => fread($this->resource, max(1, $want)));
            if (!\is_string($chunk) || $chunk === '') {
                throw $this->failed('receive', $warning);
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /**
     * Reads what is left until the server closes, at most `$most` bytes.
     *
     * @throws TurborelException SQLSTATE 08006 if the wait runs out
     */
    public function rest(int $most): string
    {
        $bytes = '';
        while (\strlen($bytes) < $most && !feof($this->resource)) {
            $want = max(1, min(self::CHUNK, $most - \strlen($bytes)));
            [$chunk, $warning] = Quietly::call(fn(): string|false => fread($this->resource, $want));
            if (!\is_string($chunk) || $chunk === '') {
                if ($this->timedOut()) {
                    throw $this->failed('receive', $warning);
                }
                break;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** Whether bytes are waiting to be read, or arrive within `$within` seconds; a stream not open has none. */
    public function waiting(float $within): bool
    {
        if (!\is_resource($this->resource)) {
            return false;
        }
        $read = [$this->resource];
        $none = null;
        $seconds = (int) floor($within);
        [$ready] = Quietly::call(static fn(): int|false => stream_select($read, $none, $none, $seconds, (int) (($within - $seconds) * self::MICROS)));

        return \is_int($ready) && $ready > 0;
    }

    /** The TLS protocol the stream speaks, as `TLSv1.3`; none of a stream TLS does not secure. */
    public function tlsProtocol(): ?string
    {
        if (!\is_resource($this->resource)) {
            return null;
        }
        return stream_get_meta_data($this->resource)['crypto']['protocol'] ?? null;
    }

    /** Closes the stream, if it is open. */
    public function close(): void
    {
        if (\is_resource($this->resource)) {
            Quietly::call(fn(): bool => fclose($this->resource));
        }
    }

    /** Whether the last wait ran out. */
    private function timedOut(): bool
    {
        return \is_resource($this->resource) && stream_get_meta_data($this->resource)['timed_out'];
    }

    /** The failure of `$what`, saying whether the server did not answer in time, or what PHP said, `$warning`. */
    private function failed(string $what, ?string $warning): TurborelException
    {
        if ($this->timedOut()) {
            return ConnectionFailure::because("cannot {$what}: the server did not answer in time");
        }
        if (\is_resource($this->resource) && feof($this->resource)) {
            return ConnectionFailure::because("cannot {$what}: the server closed the connection");
        }

        return ConnectionFailure::because("cannot {$what}: " . ($warning ?? 'the connection failed'));
    }
}
