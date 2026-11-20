<?php

declare(strict_types=1);

namespace Turborel\Tests\Support;

use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Tls;
use Turborel\Session\Trust;
use Turborel\Tests\Server\LiveServer;
use Turborel\TurborelException;

/**
 * What the live tests share (RULES.md R-TEST-7): the run's server, skipped
 * where TURBOREL_TEST_SERVER names none, and its targets and connections.
 */
abstract class LiveTestCase extends CodecTestCase
{
    /**
     * The run's server; each test is skipped where none is named.
     *
     * @throws TurborelException if it cannot be set up
     */
    final protected static function server(): LiveServer
    {
        $server = LiveServer::get();
        if ($server === null) {
            self::markTestSkipped('TURBOREL_TEST_SERVER names no server');
        }

        return $server;
    }

    /**
     * The server's TCP target.
     *
     * @throws TurborelException never, the port being one
     */
    final protected static function tcp(): Target
    {
        self::server();

        return Target::tcp('127.0.0.1', LiveServer::PORT);
    }

    /**
     * The server's TLS target, trusting the test authority.
     *
     * @throws TurborelException never, the authority's file being there
     */
    final protected static function tls(): Target
    {
        return Target::tls('localhost', Tls::trusting(self::server()->pki->authority), LiveServer::PORT);
    }

    /**
     * The server's Unix socket.
     *
     * @throws TurborelException if the server cannot be set up
     */
    final protected static function socket(): Target
    {
        return Target::local(self::server()->socket());
    }

    /**
     * A connection as ANN, the owner, trusted as a local client over TCP.
     *
     * @throws TurborelException if it cannot connect
     */
    final protected static function owner(): Connection
    {
        return Connection::connect(self::tcp(), 'ann', new Trust());
    }
}
