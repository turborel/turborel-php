<?php

declare(strict_types=1);

namespace Turborel\Settings;

/**
 * Where a server is, as settings name it [CLIENT Where settings come from]:
 * a host name or address and its port, over TCP; or the path of a Unix
 * socket, on Windows a named pipe.
 */
final readonly class Host
{
    /** The host of its parts. */
    private function __construct(
        /** The host name or address, of TCP; empty of a local socket. */
        public string $name,
        /** The TCP port; 0 of a local socket. */
        public int $port,
        /** The path of a Unix socket or named pipe; empty of TCP. */
        public string $path,
    ) {}

    /** The server at `$name` and `$port`, over TCP. */
    public static function tcp(string $name, int $port): self
    {
        return new self($name, $port, '');
    }

    /** The server at the Unix socket, or on Windows the named pipe, of `$path`. */
    public static function local(string $path): self
    {
        return new self('', 0, $path);
    }

    /** Whether it is a local socket's. */
    public function isLocal(): bool
    {
        return $this->path !== '';
    }
}
