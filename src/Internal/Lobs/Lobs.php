<?php

declare(strict_types=1);

namespace Turborel\Internal\Lobs;

use Turborel\Internal\Codec\Reply\LobCreated;
use Turborel\Internal\Codec\Reply\LobData;
use Turborel\Internal\Codec\Request\Lob as LobRequest;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Statements\Results;
use Turborel\Lobs\LobWriter;
use Turborel\Statements\Completed;
use Turborel\TurborelException;
use Turborel\Value\Lob;
use Turborel\Value\LobKind;
use Turborel\Value\LobLocator;

/**
 * Large objects over a connection, as turborel-client's `lobs.rs` reads
 * and writes them [CLIENT Large objects]: a range read, of a value held
 * whole or by its locator [PROTOCOL Lob 'L']; a value created and
 * appended to; a locator freed, or held past its transaction's commit.
 *
 * @internal
 */
final readonly class Lobs
{
    /** The octets a read asks for at most, and a writer sends in one Append, as turborel's CHUNK_OCTETS: 256 KiB. */
    public const int CHUNK_OCTETS = 262_144;

    /** The results read over the wire. */
    private Results $results;

    /** The large objects over `$wire`. */
    public function __construct(private Wire $wire)
    {
        $this->results = new Results($wire);
    }

    /**
     * At most a chunk of `$lob` from octet `$from`, from 0, at most `$count` of them; of a character large object, its
     * characters if `$characters`, whole ones. Fewer than asked only at its end, or at a chunk's.
     *
     * @throws TurborelException SQLSTATE 0F001 if its locator is no longer valid, or SQLSTATE 08006
     */
    public function read(Lob|LobLocator $lob, int|string $from, int|string $count, bool $characters): string
    {
        if ($lob instanceof Lob) {
            return Held::range($lob, (int) $from, (int) min($count, \PHP_INT_MAX), $characters);
        }
        $reply = $this->request(LobRequest::read($lob->locator, $from, $count, $characters));
        if (!$reply instanceof LobData) {
            throw $this->wire->lose('the server sent a locator for a Read');
        }

        return $reply->data;
    }

    /**
     * A new large object of `$kind`, written a piece at a time.
     *
     * @throws TurborelException the server's, or SQLSTATE 08006
     */
    public function create(LobKind $kind): LobWriter
    {
        $reply = $this->request(LobRequest::create($kind));
        if (!$reply instanceof LobCreated) {
            throw $this->wire->lose('the server sent data for a Create');
        }

        return new LobWriter($this, $kind, $reply->locator);
    }

    /**
     * `$lob`'s octets as a stream PHP's file functions read, a chunk read at a time.
     *
     * @return resource
     *
     * @throws TurborelException SQLSTATE 08006 if PHP cannot open the stream
     */
    public function reader(Lob|LobLocator $lob): mixed
    {
        return LobStream::open(new Reader($this, $lob));
    }

    /**
     * Sends `$octets` to the end of the large object of `$locator`.
     *
     * @throws TurborelException the server's, as SQLSTATE 22021 of a character one's octets not UTF-8, or SQLSTATE 08006
     */
    public function append(int $locator, string $octets): void
    {
        $this->wire->send(LobRequest::append($locator, $octets));
        $this->results->completion();
    }

    /**
     * `$lob`'s locator no longer valid, nor the value it named.
     *
     * @throws TurborelException SQLSTATE 0F001 if it is not valid, or SQLSTATE 08006
     */
    public function free(LobLocator $lob): Completed
    {
        $this->wire->send(LobRequest::free($lob->locator));

        return Completed::of($this->results->completion());
    }

    /**
     * `$lob`'s locator valid past its transaction's commit, until freed or a transaction is rolled back.
     *
     * @throws TurborelException SQLSTATE 0F001 if it is not valid, or SQLSTATE 08006
     */
    public function hold(LobLocator $lob): Completed
    {
        $this->wire->send(LobRequest::hold($lob->locator));

        return Completed::of($this->results->completion());
    }

    /**
     * The server's Lob reply to `$request`.
     *
     * @throws TurborelException the server's, or SQLSTATE 08006 if another reply comes
     */
    private function request(LobRequest $request): LobData|LobCreated
    {
        $this->wire->send($request);
        $reply = $this->wire->receive();
        if ($reply instanceof LobData || $reply instanceof LobCreated) {
            return $reply;
        }

        throw $this->results->unexpected($reply, 'the server sent no Lob reply');
    }
}
