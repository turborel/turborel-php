# Errors

[Contents](README.md)

Every failure the driver raises is a `Turborel\TurborelException`,
whose `getSqlState()` gives its SQLSTATE and `getSqlCode()` its SQLCODE.
A frame the driver cannot read, for one, fails the connection with
SQLSTATE 08006 and SQLCODE -919. This chapter will tell, as milestone M3
fills it, the rest a failure carries, the notices and conditions a
statement raises, and how a program tells failures apart by their
codes; [Appendix B](../appendix/b-errors.md) will list them.
