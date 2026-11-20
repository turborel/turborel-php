# Errors

[Contents](README.md)

This page will describe the driver's error: its SQLCODE, SQLSTATE and
message, the rule it cites, its position, whether a transaction is still
active, its diagnostics and its cause; and the failures the driver
raises itself, each with the SQLCODE and SQLSTATE turborel-client gives
it. It is filled at milestone M3.

What is built today: the driver's error is `Turborel\TurborelException`,
a `RuntimeException`. Its `getSqlState()` gives the failure's SQLSTATE,
five characters, and `getSqlCode()` its SQLCODE, negative for an error;
`getMessage()` is for people. A frame whose header the driver cannot
read, or whose body is longer than it takes, fails the connection with
SQLSTATE 08006 and SQLCODE -919, as turborel-client's does.
