# Appendix B: Errors

[Contents](README.md)

SQLSTATE classes, and the failures the driver itself raises, each with
its SQLCODE, all as `Turborel\TurborelException`. Today the driver
raises one: SQLSTATE 08006, SQLCODE -919, a connection lost, for a frame
it cannot read. Milestones M1 to M6 add each failure as it is built.
