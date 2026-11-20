<?php

declare(strict_types=1);

namespace Turborel\Session;

/**
 * The capabilities a client asks for in Hello, by name, and the server
 * grants in Ready, in the order of the spec's table [PROTOCOL Capabilities].
 */
final class Capability
{
    /** A Notice before a statement's completion. */
    public const string NOTICES = 'notices';

    /** A Status of each session setting as the session begins, and as a statement changes one. */
    public const string STATUS = 'status';

    /** An Error's failure carries tags 7 to 15. */
    public const string DIAGNOSTICS = 'diagnostics';

    /** The request Describe. */
    public const string DESCRIBE = 'describe';

    /** Several cursors of one prepared statement. */
    public const string CURSORS = 'cursors';

    /** The request Ping. */
    public const string PING = 'ping';

    /** The request Reset. */
    public const string RESET = 'reset';

    /** The request ChangeUser. */
    public const string CHANGE_USER = 'change-user';

    /** Attributes on Query and Execute. */
    public const string QUERY_ATTRIBUTES = 'query-attributes';

    /** Says Hello carries attributes; every server reads them, granted or not. */
    public const string CONNECTION_ATTRIBUTES = 'connection-attributes';

    /** A Query of several statements. */
    public const string MULTI_STATEMENT = 'multi-statement';

    /** Compressed frames, both ways. */
    public const string COMPRESSION = 'compression';

    /** A Complete that committed carries the commit's log position. */
    public const string COMMIT_POSITION = 'commit-position';

    /** The requests CopyIn, CopyData and CopyEnd. */
    public const string COPY = 'copy';

    /** A Complete carries the rows its statement counted, and the server's time. */
    public const string ROW_COUNTS = 'row-counts';

    /** A Complete of an INSERT carries the identity values it generated. */
    public const string GENERATED_VALUES = 'generated-values';

    /** An Execute that ends the transaction ends it with its cursor. */
    public const string FETCH_ENDS = 'fetch-ends';

    /** A Complete carries its statement's conditions in place of Notices. */
    public const string CONDITIONS = 'conditions';

    /** A Prepared and a Description carry each column's declared type, tail 19. */
    public const string DECLARED_TYPES = 'declared-types';

    /** Every capability, in the spec's order. */
    public const array ALL = [
        self::NOTICES, self::STATUS, self::DIAGNOSTICS, self::DESCRIBE, self::CURSORS, self::PING, self::RESET,
        self::CHANGE_USER, self::QUERY_ATTRIBUTES, self::CONNECTION_ATTRIBUTES, self::MULTI_STATEMENT, self::COMPRESSION,
        self::COMMIT_POSITION, self::COPY, self::ROW_COUNTS, self::GENERATED_VALUES, self::FETCH_ENDS, self::CONDITIONS,
        self::DECLARED_TYPES,
    ];
}
