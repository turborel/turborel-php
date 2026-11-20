# TODO

Open work, by milestone, in the order the drivers' plan builds it. Each
item says what is missing; [CONFORMANCE.md](CONFORMANCE.md) has a row for
each part of the protocol and of turborel-client, with its milestone.
How the driver is built is in [RULES.md](RULES.md). Finished work leaves
this file, and git keeps its history.

Each milestone also writes the manual's chapters and pages for what it
builds, as each stub in `doc/` names it.

Code never cites this file (RULES.md R-DOC-3).

[M1](#m1-codec) · [M2](#m2-connect) · [M3](#m3-statements) ·
[M4](#m4-large-objects-and-bulk) · [M5](#m5-settings) ·
[M6](#m6-throughput) · [M7](#m7-standard-api) · [M8](#m8-parity-audit)

---

## M1. Codec

The protocol's encoding, with no I/O, each part with its codec tests from
the spec: frames and their limits, the fields, the tail and its tag
order, all 27 data types and values at their edges, JSON's binary tree
(turborel RULES.md D89) both ways, UUIDs (D184), and compressed frames.
The spec's worked example is the first test.

## M2. Connect

TCP, Unix sockets and Windows named pipes; TLS begun on the same port;
the mechanisms trust, password, certificate, SCRAM-SHA-256 and
SCRAM-SHA-256-PLUS, by `tls-server-end-point` from the peer's
certificate, with the SCRAM key cache; Hello, the capabilities, Ready and
Status; the connect and read timeouts; terminate and cancel. Live tests
start here, against a server set up in `tests/Live` with test
certificates, SCRAM users and SERVER.TRUST_LOCAL, in CI against the
nightly image.

## M3. Statements

Query and its rows streamed; prepared statements, cursors, numbered
cursors and describe; failures, diagnostics, notices and conditions;
autocommit; what a completion tells; several statements in one request,
query attributes, ping, reset and change-user; XML values and UUIDs.

## M4. Large objects and bulk

Locators, read in chunks and written through a stream; copy; import of
CSV and tab-separated text; export as CSV and as native frames.

## M5. Settings

The `turborel://` URI, options, TURBOREL_* variables, the service file
and the password file, read as turborel-client reads them, its tests'
cases among the driver's; several hosts and load balancing; the target
role; TLS modes; channel binding; application_name.

## M6. Throughput

Pipelines with their 64 KiB window and skipping on error; pools with
PoolConfig's defaults, a Ping check and a Reset on return; the SQL-agent.

## M7. Standard API

The PDO-style API (RULES.md D2) over the client, and the manual, `doc/`,
complete: every chapter of the User's Guide and every page of the API
Reference and the Appendices written, every example run.

## M8. Parity audit

Every row of CONFORMANCE.md checked against turborel-client and the
spec, the coverage floor held, this file empty.
