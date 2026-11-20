# Conformance

What turborel-php implements of turborel's native protocol, version 1, and
of turborel-client's features, row by row. The matrix is the same in every
turborel driver: one row for each message, capability, type and value tag
of `doc/client/05-protocol.md`, in its order, then each feature of
turborel-client, by the layers of the drivers' plan, from
`doc/client/02-library.md` and `02-library-connections.md`. Where the spec
and turborel-client's code differ, the code is right (RULES.md D1).

A row's reference is the spec's or the client manual's section, cited as
the tests cite it (RULES.md R-TRACE-1), or turborel's source where the spec
does not yet say. Its milestone is the one in [TODO.md](TODO.md) that
builds it. Its status is **not yet**, or **done** with the choices made
and the tests that verify it.

[The protocol](#the-protocol) · [turborel-client](#turborel-client)

---

## The protocol

### Frames

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Frame header: kind `u8`, length `u32` little-endian | [PROTOCOL Frames] | M1 | not yet |
| A kind not known, a body cut short, or bytes left over fail the connection (SQLCODE -919, SQLSTATE 08006) | [PROTOCOL Frames] | M1 | not yet |
| Replies of at most 16 MiB of body, or as the program sets | [PROTOCOL Frames] | M1 | not yet |

### Compressed frames

| Item | Reference | Milestone | Status |
|---|---|---|---|
| `z` frames read: kind, length, zlib stream, inflated to at most `length` | [PROTOCOL Compressed frames] | M1 | not yet |
| `z` frames written, past a threshold the client chooses, where shorter | [PROTOCOL Compressed frames] | M1 | not yet |
| A `z` inside a `z`, a wrong length, or a `z` not granted fails the connection | [PROTOCOL Compressed frames] | M1 | not yet |

### Values and fields

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Field: `u8`, `u32`, `u64` | [PROTOCOL Values and fields] | M1 | not yet |
| Field: `i128` | [PROTOCOL Values and fields] | M1 | not yet |
| Field: flag (0 or 1, else the connection fails) | [PROTOCOL Values and fields] | M1 | not yet |
| Field: string | [PROTOCOL Values and fields] | M1 | not yet |
| Field: bytes | [PROTOCOL Values and fields] | M1 | not yet |
| Field: list | [PROTOCOL Values and fields] | M1 | not yet |
| Field: strings | [PROTOCOL Values and fields] | M1 | not yet |
| Field: key, 16 bytes | [PROTOCOL Values and fields] | M1 | not yet |
| A count larger than what follows fails as the body runs out, reserving nothing | [PROTOCOL Values and fields] | M1 | not yet |

### Data types

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Type tag 1: CHARACTER | [PROTOCOL Data types] | M1 | not yet |
| Type tag 2: exact numeric | [PROTOCOL Data types] | M1 | not yet |
| Type tag 3: approximate numeric | [PROTOCOL Data types] | M1 | not yet |
| Type tag 4: a plug-in's type | [PROTOCOL Data types] | M1 | not yet |
| Type tag 5: CHARACTER VARYING | [PROTOCOL Data types] | M1 | not yet |
| Type tag 6: BINARY | [PROTOCOL Data types] | M1 | not yet |
| Type tag 7: BINARY VARYING | [PROTOCOL Data types] | M1 | not yet |
| Type tag 8: DATE | [PROTOCOL Data types] | M1 | not yet |
| Type tag 9: TIME | [PROTOCOL Data types] | M1 | not yet |
| Type tag 10: TIME WITH TIME ZONE | [PROTOCOL Data types] | M1 | not yet |
| Type tag 11: TIMESTAMP | [PROTOCOL Data types] | M1 | not yet |
| Type tag 12: TIMESTAMP WITH TIME ZONE | [PROTOCOL Data types] | M1 | not yet |
| Type tag 13: INTERVAL, each qualifier | [PROTOCOL Data types] | M1 | not yet |
| Type tag 14: BOOLEAN | [PROTOCOL Data types] | M1 | not yet |
| Type tag 15: DECFLOAT | [PROTOCOL Data types] | M1 | not yet |
| Type tag 16: array | [PROTOCOL Data types] | M1 | not yet |
| Type tag 17: row | [PROTOCOL Data types] | M1 | not yet |
| Type tag 18: CHARACTER LARGE OBJECT | [PROTOCOL Data types] | M1 | not yet |
| Type tag 19: BINARY LARGE OBJECT | [PROTOCOL Data types] | M1 | not yet |
| Type tag 22: multiset | [PROTOCOL Data types] | M1 | not yet |
| Type tag 23: structured type | [PROTOCOL Data types] | M1 | not yet |
| Type tag 24: reference type | [PROTOCOL Data types] | M1 | not yet |
| Type tag 25: JSON | [PROTOCOL Data types] | M1 | not yet |
| Type tag 26: XML, its modifiers | [PROTOCOL Data types] | M1 | not yet |
| Type tag 27: UUID, no parameters (turborel RULES.md D184) | turborel `protocol/src/fields.rs` | M1 | not yet |

### Values

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Value tag 0: null | [PROTOCOL Values] | M1 | not yet |
| Value tag 1: character string | [PROTOCOL Values] | M1 | not yet |
| Value tag 2: exact number: units `i128`, scale `u32` | [PROTOCOL Values] | M1 | not yet |
| Value tag 3: approximate number: IEEE 754 double, NaN and -0.0 kept | [PROTOCOL Values] | M1 | not yet |
| Value tag 4: a plug-in's value | [PROTOCOL Values] | M1 | not yet |
| Value tag 6: binary string | [PROTOCOL Values] | M1 | not yet |
| Value tag 8: date | [PROTOCOL Values] | M1 | not yet |
| Value tag 9: time | [PROTOCOL Values] | M1 | not yet |
| Value tag 11: timestamp: zoned, precision, displacement, instant | [PROTOCOL Values] | M1 | not yet |
| Value tag 13: interval | [PROTOCOL Values] | M1 | not yet |
| Value tag 14: truth value | [PROTOCOL Values] | M1 | not yet |
| Value tag 15: decimal floating point | [PROTOCOL Values] | M1 | not yet |
| Value tag 16: array, nested | [PROTOCOL Values] | M1 | not yet |
| Value tag 17: row | [PROTOCOL Values] | M1 | not yet |
| Value tag 20: large object, whole | [PROTOCOL Values] | M1 | not yet |
| Value tag 21: large object, by locator | [PROTOCOL Values] | M1 | not yet |
| Value tag 22: multiset | [PROTOCOL Values] | M1 | not yet |
| Value tag 23: structured value | [PROTOCOL Values] | M1 | not yet |
| Value tag 24: reference | [PROTOCOL Values] | M1 | not yet |
| Value tag 25: JSON, read | [PROTOCOL Values] | M1 | not yet |
| Value tag 26: XML | [PROTOCOL Values] | M1 | not yet |
| Value tag 25: JSON, its binary tree (turborel RULES.md D89) decoded to PHP's JSON values or text | turborel RULES.md D89, `src/json/binary.rs` | M1 | not yet |
| Value tag 25: JSON parameters encoded to the binary tree | turborel RULES.md D89, `src/json/binary/write.rs` | M1 | not yet |
| Value tag 27: UUID, as bytes: a count of 16, then the octets | turborel RULES.md D184, `protocol/src/fields.rs` | M1 | not yet |

### Composite fields

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Composite: row | [PROTOCOL Composite fields] | M1 | not yet |
| Composite: column | [PROTOCOL Composite fields] | M1 | not yet |
| Composite: parameter (its varying flag) | [PROTOCOL Composite fields] | M1 | not yet |
| Composite: failure (SQLCODE, SQLSTATE, message, rule, position, active, tail 7 to 15) | [PROTOCOL Composite fields] | M1 | not yet |

### The tail

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Tags in increasing order; a tag not taken, or not past the last, fails the connection | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 1: attributes | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 2: multi | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 3: cursor | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 4: position | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 5: more | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 6: rows | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 7: severity | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 8: detail | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 9: hint | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 10: context | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 11: schema | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 12: table | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 13: column | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 14: data type | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 15: constraint | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 16: generated | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 17: conditions | [PROTOCOL The tail] | M1 | not yet |
| Tail tag 18: time | [PROTOCOL The tail] | M1 | not yet |
| At most 32 attributes; a name of 1 to 64 bytes, a value of at most 1024 | [PROTOCOL The tail] | M1 | not yet |

### Opening a connection

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Hello, then Challenge and Answer steps, then Status and Ready | [PROTOCOL Opening a connection] | M2 | not yet |
| Ready at login never compressed | [PROTOCOL Opening a connection] | M2 | not yet |

### Mechanisms

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Mechanism `trust` | [PROTOCOL Mechanisms] | M2 | not yet |
| Mechanism `password`: only over TLS, a Unix socket or a named pipe | [PROTOCOL Mechanisms] | M2 | not yet |
| Mechanism `certificate`: the TLS client certificate | [PROTOCOL Mechanisms] | M2 | not yet |
| Mechanism `scram-sha-256`: RFC 5802 and RFC 7677 | [PROTOCOL Mechanisms] | M2 | not yet |
| Mechanism `scram-sha-256-plus`: bound by `tls-exporter` on TLS 1.3, `tls-server-end-point` on TLS 1.2 | [PROTOCOL Mechanisms] | M2 | not yet |

### Capabilities

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Capability `notices` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `status` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `diagnostics` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `describe` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `cursors` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `ping` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `reset` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `change-user` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `query-attributes` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `connection-attributes` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `multi-statement` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `compression` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `commit-position` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `copy` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `row-counts` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `generated-values` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `fetch-ends` | [PROTOCOL Capabilities] | M2 | not yet |
| Capability `conditions` | [PROTOCOL Capabilities] | M2 | not yet |
| No request or tail field of a capability not granted is sent | [PROTOCOL Capabilities] | M2 | not yet |

### Requests

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Request Hello 'H' | [PROTOCOL Hello 'H'] | M2 | not yet |
| Request Answer 'A' | [PROTOCOL Answer 'A'] | M2 | not yet |
| Request Query 'Q' | [PROTOCOL Query 'Q'] | M3 | not yet |
| Request Prepare 'P' | [PROTOCOL Prepare 'P'] | M3 | not yet |
| Request Execute 'E' | [PROTOCOL Execute 'E'] | M3 | not yet |
| Request Fetch 'F' | [PROTOCOL Fetch 'F'] | M3 | not yet |
| Request Close 'C' | [PROTOCOL Close 'C'] | M3 | not yet |
| Request Deallocate 'D' | [PROTOCOL Deallocate 'D'] | M3 | not yet |
| Request Lob 'L' | [PROTOCOL Lob 'L'] | M4 | not yet |
| Request Cancel 'Z' | [PROTOCOL Cancel 'Z'] | M2 | not yet |
| Request Terminate 'X' | [PROTOCOL Terminate 'X'] | M2 | not yet |
| Request Describe 'Y' | [PROTOCOL Describe 'Y'] | M3 | not yet |
| Request Ping 'G' | [PROTOCOL Ping 'G'] | M3 | not yet |
| Request Reset 'W' | [PROTOCOL Reset 'W'] | M3 | not yet |
| Request ChangeUser 'U' | [PROTOCOL ChangeUser 'U'] | M3 | not yet |
| Request CopyIn 'I' | [PROTOCOL CopyIn 'I'] | M4 | not yet |
| Request CopyData 'J' | [PROTOCOL CopyData 'J'] | M4 | not yet |
| Request CopyEnd 'N' | [PROTOCOL CopyEnd 'N'] | M4 | not yet |

### Replies

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Reply Challenge 'C' | [PROTOCOL Challenge 'C'] | M2 | not yet |
| Reply Ready 'R' | [PROTOCOL Ready 'R'] | M2 | not yet |
| Reply Prepared 'P' | [PROTOCOL Prepared 'P'] | M3 | not yet |
| Reply Description 'D' | [PROTOCOL Description 'D'] | M3 | not yet |
| Reply Rows 'B' | [PROTOCOL Rows 'B'] | M3 | not yet |
| Reply Suspended 'S' | [PROTOCOL Suspended 'S'] | M3 | not yet |
| Reply Complete 'K' | [PROTOCOL Complete 'K'] | M3 | not yet |
| Reply Error 'E' | [PROTOCOL Error 'E'] | M3 | not yet |
| Reply Lob 'L' | [PROTOCOL Lob 'L'] | M4 | not yet |
| Reply Notice 'N' | [PROTOCOL Notice 'N'] | M3 | not yet |
| Reply Status 'T' | [PROTOCOL Status 'T'] | M2 | not yet |
| Reply Pong 'G' | [PROTOCOL Pong 'G'] | M3 | not yet |

### Status names

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Status `AUTHORIZATION` | [PROTOCOL Status 'T'] | M2 | not yet |
| Status `SCHEMA` | [PROTOCOL Status 'T'] | M2 | not yet |
| Status `READ_ONLY` | [PROTOCOL Status 'T'] | M2 | not yet |
| Status `SERVER_VERSION` | [PROTOCOL Status 'T'] | M2 | not yet |
| Status `SERVER_READ_ONLY`: YES or NO; a replica from a primary | turborel `server/src/native/status.rs` | M2 | not yet |
| Status `CATALOG` | turborel `server/src/native/status.rs` | M2 | not yet |
| Status `OWNER`: YES or NO | turborel `server/src/native/status.rs` | M2 | not yet |
| Status of each session setting, at login and as a statement changes it | [PROTOCOL Status 'T'] | M2 | not yet |

### Conversations

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Replies in the spec's order: Rows, Notices, Statuses, then the last reply | [PROTOCOL Conversations] | M3 | not yet |

### Errors and notices

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Severity ERROR, FATAL, WARNING, NOTICE | [PROTOCOL Errors and notices] | M3 | not yet |
| Conditions told one way: tag 17, else Notices, else not at all | [PROTOCOL Errors and notices] | M3 | not yet |
| SQLSTATE 01T01: Reset or ChangeUser rolled back a transaction | [PROTOCOL Errors and notices] | M3 | not yet |
| SQLSTATE TBR01: a replica behind REPLICATION.READ_AFTER | [PROTOCOL Errors and notices] | M3 | not yet |
| An Error of class 08 (but 08003), or 28 as connecting, ends the connection | [PROTOCOL Error 'E'] | M3 | not yet |

### A worked example

| Item | Reference | Milestone | Status |
|---|---|---|---|
| The worked example's bytes, written and read | [PROTOCOL A worked example] | M1 | not yet |

---

## turborel-client

### Transport

| Item | Reference | Milestone | Status |
|---|---|---|---|
| TCP, port 7422 by default | [CLIENT Connecting] | M2 | not yet |
| Unix socket | [CLIENT Connecting] | M2 | not yet |
| Windows named pipe, `\\.\pipe\turborel` | [CLIENT Connecting] | M2 | not yet |
| TLS begun on the same port | [CLIENT Connecting] | M2 | not yet |
| Connect timeout, 30 s by default, for each step of the login | [CLIENT Timeouts] | M2 | not yet |
| Read timeout, no limit by default; changed on a connection | [CLIENT Timeouts] | M2 | not yet |
| Replies longer than the body limit lose the connection; the limit set by the program | [CLIENT Timeouts] | M2 | not yet |

### Authentication

| Item | Reference | Milestone | Status |
|---|---|---|---|
| A password or SCRAM refused over plain TCP before connecting (SQLSTATE 28000) | [CLIENT Connecting] | M2 | not yet |
| SCRAM in the clear only where the program says so | [CLIENT Connecting] | M2 | not yet |
| TLS trusting the authorities of a file, the platform's, or both | [CLIENT Connecting] | M2 | not yet |
| A client certificate presented | [CLIENT Connecting] | M2 | not yet |
| SCRAM key cache: 64 entries by salt, iterations and a keyed tag; dropped on a failed login | [CLIENT The SCRAM key cache] | M2 | not yet |

### Session

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Hello asks for every capability but compression; an offer of the program's own, attributes too | [CLIENT What a connection asks for] | M2 | not yet |
| What was granted; a method needing a capability not granted fails before sending | [CLIENT What a connection asks for] | M2 | not yet |
| The server's product and the authorization | [CLIENT Connecting] | M2 | not yet |
| Terminate, its commit's failure given; waiting at most 60 s | [CLIENT Connecting] | M2 | not yet |
| Settings as the server told them, with no round trip | [CLIENT What the server tells] | M3 | not yet |
| Notices to a handler, or the last 64 kept | [CLIENT What the server tells] | M3 | not yet |
| Conditions of the last statement | [CLIENT What the server tells] | M3 | not yet |
| Diagnostics of a failure | [CLIENT What the server tells] | M3 | not yet |
| Whether a transaction is active | [CLIENT Running statements] | M3 | not yet |
| Autocommit, by `ends` | [CLIENT Running statements] | M3 | not yet |
| Cancel by key on a connection of its own, giving up after 10 s or as the program says | [CLIENT Running statements] | M3 | not yet |
| Ping, reset and change-user | [CLIENT Controlling the session] | M3 | not yet |
| Query attributes on every later statement | [CLIENT Controlling the session] | M3 | not yet |
| Several statements as one request | [CLIENT Controlling the session] | M3 | not yet |

### Statements

| Item | Reference | Milestone | Status |
|---|---|---|---|
| A query's columns and rows, or a statement's completion | [CLIENT Running statements] | M3 | not yet |
| A query's rows streamed a batch at a time | [CLIENT Running statements] | M3 | not yet |
| Failures with SQLCODE, SQLSTATE, message, rule and position | [CLIENT Running statements] | M3 | not yet |
| Prepare, execute, parameters' and columns' types | [CLIENT Prepared statements] | M3 | not yet |
| Cursors: open, fetch, close; deallocate | [CLIENT Prepared statements] | M3 | not yet |
| Numbered cursors, and describing a statement or a cursor | [CLIENT Controlling the session] | M3 | not yet |
| Under autocommit, a cursor's transaction ending with it (`fetch-ends`) | [CLIENT What the server tells] | M3 | not yet |
| Rows affected, generated values, server time, commit position | [CLIENT What the server tells] | M3 | not yet |
| XML values read and sent | [CLIENT XML values] | M3 | not yet |
| UUIDs read and sent, by octets or text | [CLIENT UUIDs] | M3 | not yet |

### Large objects

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Locators, read by octets or characters in chunks of at most 256 KiB | [CLIENT Large objects] | M4 | not yet |
| A reader of a large object whole | [CLIENT Large objects] | M4 | not yet |
| Free and hold | [CLIENT Large objects] | M4 | not yet |
| Create, append, and a writer whose result is a parameter | [CLIENT Large objects] | M4 | not yet |

### Bulk

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Copy in, rows sent in batches, finished or abandoned | [CLIENT Copying and exporting] | M4 | not yet |
| Copy of the rows of an iterable | [CLIENT Copying and exporting] | M4 | not yet |
| Import of CSV and tab-separated text, in batches, skipping or not | [CLIENT Importing] | M4 | not yet |
| Import by copy where granted and no column is a large object | [CLIENT Copying and exporting] | M4 | not yet |
| Export as CSV, or as native frames read back | [CLIENT Copying and exporting] | M4 | not yet |

### Settings

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Settings and their defaults: host, port, user, password, tls_mode, channel_binding, application_name, connect_timeout, service, service_file, passfile, target, load_balance, tls_roots, tls_system_roots, tls_certificate, tls_key, server_name | [CLIENT Where settings come from] | M5 | not yet |
| Layers: the program's, the service file's, the environment's, the defaults | [CLIENT Where settings come from] | M5 | not yet |
| TURBOREL_* environment variables; an empty one gives nothing | [CLIENT Where settings come from] | M5 | not yet |
| The connection URI, percent-encoded | [CLIENT The connection URI] | M5 | not yet |
| The service file | [CLIENT The service file] | M5 | not yet |
| The password file | [CLIENT The password file] | M5 | not yet |
| `application_name` sent as the attribute `application` | [CLIENT Where settings come from] | M5 | not yet |
| Several hosts, tried in order or at random; each address in turn | [CLIENT Several servers] | M5 | not yet |
| Target role: any, read-write, read-only, by SERVER_READ_ONLY or INFORMATION_SCHEMA.REPLICAS | [CLIENT Several servers] | M5 | not yet |
| TLS modes: disable, prefer, require, verify-ca, verify-full | [CLIENT TLS by mode] | M5 | not yet |
| Channel binding: disable, prefer, require | [CLIENT TLS by mode] | M5 | not yet |

### Throughput

| Item | Reference | Milestone | Status |
|---|---|---|---|
| Pipelines: one outcome a request, in order | [CLIENT Pipelines] | M6 | not yet |
| Pipelines written a window of 64 KiB at a time | [CLIENT Pipelines] | M6 | not yet |
| Pipelines skipping after a failure, by savepoints | [CLIENT Pipelines] | M6 | not yet |
| Pools: PoolConfig's defaults (max 10, max_idle 10, idle_timeout 10 min, max_lifetime 1 h, wait 30 s, check, reset) | [CLIENT Pools] | M6 | not yet |
| Pools: Ping check, Reset on return, with the fallbacks of a server without them | [CLIENT Pools] | M6 | not yet |
| The SQL-agent: CONNECT, SET CONNECTION, DISCONNECT | [CLIENT The SQL-agent] | M6 | not yet |

### Standard API

| Item | Reference | Milestone | Status |
|---|---|---|---|
| PDO-style connection, statements, fetch modes, transactions and exceptions | RULES.md D2 | M7 | not yet |
