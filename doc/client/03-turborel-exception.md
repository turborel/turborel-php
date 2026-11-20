# TurborelException

[Contents](README.md)

`Turborel\TurborelException` is the driver's one error type, a
`RuntimeException`. Its `getSqlState()` gives the failure's SQLSTATE,
five characters, and `getSqlCode()` its SQLCODE, negative for an error;
`getMessage()` is for people. Milestone M3 adds the rest a failure
carries: the rule it cites, its position in the statement, whether a
transaction is active, and its diagnostics.
