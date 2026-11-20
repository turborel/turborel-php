<?php

declare(strict_types=1);

namespace Turborel\Internal\Transport;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\Internal\Tls\Context;
use Turborel\Session\Target;
use Turborel\Session\TlsChannel;
use Turborel\TurborelException;

/**
 * A target's stream opened: TCP, with Nagle's delay off; TLS begun on the
 * TCP port; a Unix socket; or on Windows a named pipe, tried again while
 * busy [CLIENT Connecting]. Each wait to connect and begin TLS is bounded
 * by the connect timeout [CLIENT Timeouts].
 *
 * @internal
 */
final class Opener
{
    /** The TLS versions a client offers: 1.2 and 1.3, as turborel-client's safe defaults. */
    private const int TLS_METHODS = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /**
     * The stream to `$target`, and of TLS its channel, each wait at most `$within` seconds, none for no limit.
     *
     * @return array{Stream, ?TlsChannel}
     *
     * @throws TurborelException SQLSTATE 08006 if it cannot be reached, or TLS fails
     */
    public static function open(Target $target, ?float $within): array
    {
        if ($target->isLocal()) {
            return [self::local($target->path, $within), null];
        }
        $context = $target->tls === null ? null : new Context($target->tls, $target->host);
        try {
            $resource = self::tcp($target, $within, $context);
            $stream = new Stream($resource);
            $stream->bound($within);
            if ($context === null) {
                return [$stream, null];
            }

            return [$stream, self::secured($resource, $stream, $context)];
        } finally {
            $context?->release();
        }
    }

    /**
     * The TCP connection to `$target`'s host and port.
     *
     * @return resource
     *
     * @throws TurborelException SQLSTATE 08006 if it cannot be reached
     */
    private static function tcp(Target $target, ?float $within, ?Context $context): mixed
    {
        $host = str_contains($target->host, ':') ? "[{$target->host}]" : $target->host;
        $options = ['socket' => ['tcp_nodelay' => true], 'ssl' => $context?->options() ?? []];
        [$resource, $warning] = Quietly::call(static fn(): mixed => stream_socket_client(
            "tcp://{$host}:{$target->port}",
            $code,
            $message,
            $within ?? (float) Stream::UNBOUNDED,
            \STREAM_CLIENT_CONNECT,
            stream_context_create($options),
        ));
        if (!\is_resource($resource)) {
            throw ConnectionFailure::because("cannot connect to {$target}: " . ($warning ?? 'it could not be reached'));
        }

        return $resource;
    }

    /**
     * Begins TLS on `$resource`, as `$context` says, and gives its channel.
     *
     * @param resource $resource
     *
     * @throws TurborelException SQLSTATE 08006 if the handshake fails
     */
    private static function secured(mixed $resource, Stream $stream, Context $context): TlsChannel
    {
        [$secured, $warning] = Quietly::call(static fn(): bool|int => stream_socket_enable_crypto($resource, true, self::TLS_METHODS));
        if ($secured !== true) {
            $stream->close();

            throw ConnectionFailure::because('the TLS handshake failed: ' . ($warning ?? 'the server did not answer in time'));
        }

        return new TlsChannel(Context::peerCertificate($resource));
    }

    /**
     * The Unix socket, or on Windows the named pipe, at `$path`.
     *
     * @throws TurborelException SQLSTATE 08006 if it cannot be reached
     */
    private static function local(string $path, ?float $within): Stream
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return Pipe::open($path, $within);
        }
        [$resource, $warning] = Quietly::call(static fn(): mixed => stream_socket_client(
            "unix://{$path}",
            $code,
            $message,
            $within ?? (float) Stream::UNBOUNDED,
        ));
        if (!\is_resource($resource)) {
            throw ConnectionFailure::because("cannot connect to {$path}: " . ($warning ?? 'it could not be reached'));
        }
        $stream = new Stream($resource);
        $stream->bound($within);

        return $stream;
    }
}
