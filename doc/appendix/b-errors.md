# Appendix B: Errors

[Contents](README.md)

Every failure reaches a program as a `Turborel\TurborelException` of a
SQLSTATE and a SQLCODE ([Errors](../client/30-errors.md)). The first two
characters of the SQLSTATE are its class.

## The driver's own

| SQLSTATE | SQLCODE | Raised when | Ends the connection |
|---|---|---|---|
| 08006 | -919 | The server is not reached, TLS fails, a wait runs out, a reply cannot be read or comes out of turn, or the connection is lost | yes, but of a capability not granted, refused before anything is sent |
| 28000 | -919 | A secret would go over plain TCP; SCRAM's exchange is refused | yes, of a login |
| 22003 | -301 | A number, or a field's value, is out of its range | no |
| 22021 | -301 | A string is not UTF-8 | no |
| 22032 | -301 | JSON text does not parse | no |
| TB916 | -916 | A JSON stored form is damaged | no |
| 0A000 | -900 | JSON is past turborel's limits, nested more than 128 deep or a stored form past 256 MiB | no |
| 2200N | -301 | An XML value's text is not well-formed content | no |
| 07006 | -921 | The protocol has no value for a PHP value | no |

## The server's

The server's failures come as it sends them. Their classes, those a
program meets most:

| Class | What | Ends the connection |
|---|---|---|
| 01 | A warning: a condition of the statement, never thrown | no |
| 02 | No data: SQLCODE 100, a completion, never thrown | no |
| 07 | A dynamic SQL error, as 07001 of values not one for each parameter | no |
| 08 | A connection exception, as 08004 of a connection refused; 08003 names another connection | yes, but 08003 |
| 22 | A data exception, as 22012 of a division by zero, or 2200W of an XML value that cannot be serialized | no |
| 23 | An integrity constraint violation, as of a duplicate key | no |
| 24 | An invalid cursor state | no |
| 26 | An invalid SQL statement name: a prepared statement that is no more | no |
| 28 | Credentials refused: SQLCODE -919 as a connection is made, or as a change of user is refused | yes, of SQLCODE -919 |
| 2A | A syntax error or access rule violation | no |
| 40 | A transaction rollback, as 40002 of a commit that fails | no |
| TB | turborel's own: TB920, SQLCODE -920, a statement cancelled; TBR01, SQLCODE -920, a replica that had not applied REPLICATION.READ_AFTER's position in time | no |

A notice of the server's own comes as a `Turborel\Session\Notice`, never
as a failure: 01T01, of severity NOTICE, tells that a reset or a change
of user rolled a transaction back.
