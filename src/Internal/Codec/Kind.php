<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

/**
 * The kinds of message, each read in the direction it travels, so `C`
 * from a client is Close and `C` from the server is Challenge
 * [PROTOCOL Frames] [PROTOCOL Requests] [PROTOCOL Replies].
 *
 * @internal
 */
final class Kind
{
    /** Hello 'H' [PROTOCOL Hello 'H']. */
    public const int HELLO = 0x48;

    /** Answer 'A' [PROTOCOL Answer 'A']. */
    public const int ANSWER = 0x41;

    /** Query 'Q' [PROTOCOL Query 'Q']. */
    public const int QUERY = 0x51;

    /** Prepare 'P' [PROTOCOL Prepare 'P']. */
    public const int PREPARE = 0x50;

    /** Execute 'E' [PROTOCOL Execute 'E']. */
    public const int EXECUTE = 0x45;

    /** Fetch 'F' [PROTOCOL Fetch 'F']. */
    public const int FETCH = 0x46;

    /** Close 'C' [PROTOCOL Close 'C']. */
    public const int CLOSE = 0x43;

    /** Deallocate 'D' [PROTOCOL Deallocate 'D']. */
    public const int DEALLOCATE = 0x44;

    /** Lob 'L', the request and the reply [PROTOCOL Lob 'L']. */
    public const int LOB = 0x4C;

    /** Cancel 'Z' [PROTOCOL Cancel 'Z']. */
    public const int CANCEL = 0x5A;

    /** Terminate 'X' [PROTOCOL Terminate 'X']. */
    public const int TERMINATE = 0x58;

    /** Describe 'Y' [PROTOCOL Describe 'Y']. */
    public const int DESCRIBE = 0x59;

    /** Ping 'G', and Pong 'G' [PROTOCOL Ping 'G'] [PROTOCOL Pong 'G']. */
    public const int PING = 0x47;

    /** Reset 'W' [PROTOCOL Reset 'W']. */
    public const int RESET = 0x57;

    /** ChangeUser 'U' [PROTOCOL ChangeUser 'U']. */
    public const int CHANGE_USER = 0x55;

    /** CopyIn 'I' [PROTOCOL CopyIn 'I']. */
    public const int COPY_IN = 0x49;

    /** CopyData 'J' [PROTOCOL CopyData 'J']. */
    public const int COPY_DATA = 0x4A;

    /** CopyEnd 'N' [PROTOCOL CopyEnd 'N']. */
    public const int COPY_END = 0x4E;

    /** Debug 'V', with `debugger` [PROTOCOL Debug 'V'] *(extension)*. */
    public const int DEBUG = 0x56;

    /** Challenge 'C' [PROTOCOL Challenge 'C']. */
    public const int CHALLENGE = 0x43;

    /** Ready 'R' [PROTOCOL Ready 'R']. */
    public const int READY = 0x52;

    /** Prepared 'P' [PROTOCOL Prepared 'P']. */
    public const int PREPARED = 0x50;

    /** Description 'D' [PROTOCOL Description 'D']. */
    public const int DESCRIPTION = 0x44;

    /** Rows 'B' [PROTOCOL Rows 'B']. */
    public const int ROWS = 0x42;

    /** Suspended 'S' [PROTOCOL Suspended 'S']. */
    public const int SUSPENDED = 0x53;

    /** Complete 'K' [PROTOCOL Complete 'K']. */
    public const int COMPLETE = 0x4B;

    /** Error 'E' [PROTOCOL Error 'E']. */
    public const int ERROR = 0x45;

    /** Notice 'N' [PROTOCOL Notice 'N']. */
    public const int NOTICE = 0x4E;

    /** Status 'T' [PROTOCOL Status 'T']. */
    public const int STATUS = 0x54;

    /** Pong 'G' [PROTOCOL Pong 'G']. */
    public const int PONG = 0x47;

    /** Debugged 'V', with `debugger` [PROTOCOL Debugged 'V'] *(extension)*. */
    public const int DEBUGGED = 0x56;

    /** Event 'W', with `debugger`, sent unasked [PROTOCOL Event 'W'] *(extension)*. */
    public const int EVENT = 0x57;

    /** A compressed frame 'z', the same both ways [PROTOCOL Compressed frames]. */
    public const int COMPRESSED = 0x7A;
}
