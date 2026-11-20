<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The headings a test may cite (RULES.md D6, R-TRACE-2), as Turborel's
 * manual prints them, its pages' titles among them: `[PROTOCOL Frames]`
 * cites doc/client/05-protocol.md, and `[CLIENT Pipelines]`
 * doc/client/02-library.md or 02-library-connections.md, copied at the
 * Turborel commit CONFORMANCE.md names, e4f08f77. A heading the manual
 * changes is changed here in the same commit.
 */
final class Headings
{
    /** The headings of doc/client/05-protocol.md, its title first, in its order. */
    public const array PROTOCOL = [
        'The Native Protocol', 'Frames', 'Compressed frames', 'Values and fields', 'Data types', 'Values',
        'Composite fields', 'The tail', 'Opening a connection', 'Mechanisms', 'Capabilities',
        'Requests', "Hello 'H'", "Answer 'A'", "Query 'Q'", "Prepare 'P'", "Execute 'E'",
        "Fetch 'F'", "Close 'C'", "Deallocate 'D'", "Lob 'L'", "Cancel 'Z'", "Terminate 'X'",
        "Describe 'Y'", "Ping 'G'", "Reset 'W'", "ChangeUser 'U'", "CopyIn 'I'", "CopyData 'J'",
        "CopyEnd 'N'", 'Replies', "Challenge 'C'", "Ready 'R'", "Prepared 'P'",
        "Description 'D'", "Rows 'B'", "Suspended 'S'", "Complete 'K'", "Error 'E'",
        "Notice 'N'", "Status 'T'", "Pong 'G'", 'Conversations', 'Errors and notices',
        'A worked example',
    ];

    /** The headings of doc/client/02-library.md, then 02-library-connections.md, each its title first. */
    public const array CLIENT = [
        'The Client Library', 'Connecting', 'Timeouts', 'Running statements', 'Prepared statements', 'Large objects',
        'XML values', 'UUIDs', 'Importing', 'What a connection asks for', 'What the server tells',
        'Controlling the session', 'Copying and exporting', 'The SQL-agent',
        'Settings, Pipelines and Pools', 'Connecting by settings', 'Where settings come from', 'The connection URI',
        'The service file', 'The password file', 'Several servers', 'TLS by mode', 'Pipelines',
        'Pools', 'The SCRAM key cache',
    ];
}
