# Security

Report a vulnerability privately, never in a public issue: on the
repository's **Security** tab, choose **Report a vulnerability**. Only the
maintainers see the report.

What counts, for a driver:

- TLS not used, or the server's certificate not verified, where the
  program asked for it;
- a password, a SCRAM key, a cancel key or anything derived from one
  disclosed, in a message, a trace, a log or on the wire;
- a server's reply, or a file the driver reads, such as a service file
  or a password file, that crashes the client, runs code, or takes its
  memory without bound.

A wrong value, a lost connection with no attacker, or a failure only the
program's own author can cause is a bug: report it with the **Bug** form,
its impact in the title (CONTRIBUTING.md, Reporting issues).

Say in the report:

- what an attacker needs, such as a server of their own, a place on the
  network, or a file the driver reads;
- what they gain;
- the commit you tested (`git rev-parse --short=12 HEAD`);
- the smallest steps that show it.

You will hear back within a week. The fix is made in private and
published with an advisory that credits you, unless you ask not to be
named.
