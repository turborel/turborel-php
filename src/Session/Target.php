<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\Misuse;
use Turborel\TurborelException;

/**
 * Where a server is, and how a client reaches it [CLIENT Connecting]:
 * over TCP, port 7422 unless told another; over a Unix socket, or on
 * Windows a named pipe, as `\\.\pipe\turborel`; or over TLS, begun on the
 * TCP port, the server telling it by the handshake's first byte
 * [PROTOCOL The Native Protocol]. A connection remembers its target, so it
 * can be reached again to cancel a statement.
 */
final readonly class Target
{
    /** The TCP port a server listens on unless told another [PROTOCOL The Native Protocol]. */
    public const int DEFAULT_PORT = 7422;

    /** The largest TCP port. */
    private const int MAX_PORT = 65_535;

    /** The target of its parts; made by `tcp`, `local` or `tls`. */
    private function __construct(
        /** The host's name or address, of TCP or TLS; empty of a local socket. */
        public string $host,
        /** The TCP port; 0 of a local socket. */
        public int $port,
        /** The path of a Unix socket or named pipe; empty over TCP. */
        public string $path,
        /** The TLS the connection begins, if any. */
        public ?Tls $tls,
    ) {}

    /**
     * The server at `$host`, on TCP port `$port`.
     *
     * @throws TurborelException SQLSTATE TB910 if the port is not one
     */
    public static function tcp(string $host, int $port = self::DEFAULT_PORT): self
    {
        return new self($host, self::port($port), '', null);
    }

    /** The server at the Unix socket, or on Windows the named pipe, `$path`. */
    public static function local(string $path): self
    {
        return new self('', 0, $path, null);
    }

    /**
     * The server at `$host`, on TCP port `$port`, over TLS as `$tls` says.
     *
     * @throws TurborelException SQLSTATE TB910 if the port is not one
     */
    public static function tls(string $host, Tls $tls, int $port = self::DEFAULT_PORT): self
    {
        return new self($host, self::port($port), '', $tls);
    }

    /** Whether it is plain TCP, where a secret is never sent [CLIENT Connecting]. */
    public function isPlainTcp(): bool
    {
        return $this->path === '' && $this->tls === null;
    }

    /** Whether it is a Unix socket or a named pipe. */
    public function isLocal(): bool
    {
        return $this->path !== '';
    }

    /** Where it is, as people write it: `host:port`, `tls://host:port` or the socket's path. */
    public function __toString(): string
    {
        if ($this->isLocal()) {
            return $this->path;
        }
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;

        return ($this->tls === null ? '' : 'tls://') . "{$host}:{$this->port}";
    }

    /**
     * `$port`, a TCP port.
     *
     * @throws TurborelException SQLSTATE TB910 if it is not one
     */
    private static function port(int $port): int
    {
        if ($port < 1 || $port > self::MAX_PORT) {
            throw Misuse::of("{$port} is not a TCP port");
        }

        return $port;
    }
}
