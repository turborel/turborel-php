# Contributing to turborel-php

turborel-php carries a program's data to and from a turborel server, and
a value read or sent wrongly is data lost or damaged as surely as a bad
disk. So a change is judged first on whether it keeps every value and
every failure exactly as the protocol and turborel-client have them, and
then on what it adds. The process follows the Linux kernel's, adapted to
pull requests, as turborel's own does.

[Before you start](#before-you-start) · [Setting up](#setting-up) ·
[Making a change](#making-a-change) ·
[Changes that touch the wire](#changes-that-touch-the-wire) ·
[Commit messages](#commit-messages) · [Pull requests](#pull-requests) ·
[Review](#review) · [Reporting issues](#reporting-issues) ·
[Security](#security) · [License](#license)

---

## Before you start

Read [RULES.md](RULES.md) §3 (code), §4 (tests), §7 (process) and the
decisions for your area. Every rule applies to every change, and
`composer verify` checks those a tool can.
[CONFORMANCE.md](CONFORMANCE.md) says what is implemented of the protocol
and of turborel-client, and [TODO.md](TODO.md) lists open work.

Open an issue first for anything larger than a fix. That covers new
features, changes to a decision in RULES.md and new dependencies
(R-DEP-1). Agreeing on the design first saves everyone a rewrite.

The protocol starts from its spec, turborel's
`doc/client/05-protocol.md`, and what turborel-client does. Read the
section before writing code, and cite it by its heading, as
`[PROTOCOL Frames]`. Never cite from memory. Where the spec and
turborel-client's code differ, the code is right; say so in an issue on
turborel too.

## Setting up

You need PHP 8.3 or later, 64-bit, with the extensions openssl, zlib,
bcmath and simplexml, and Composer 2.

```
composer install                 # the development tools, as composer.lock pins them
composer install-hooks           # run verify before each commit (once per clone)
composer verify                  # every check a change must pass
composer coverage                # the tests with coverage, and the floor
```

`composer verify` validates composer.json, checks the format
(`composer format` fixes it), runs the project's own checks
(`composer check`), PHPStan (`composer analyse`) and the tests
(`composer test`). `composer coverage` needs pcov or Xdebug; RULES.md D9
says how to load pcov for one run without installing it.

CI runs `verify` on Linux, macOS and Windows, on PHP 8.3, 8.4 and 8.5,
and coverage on Linux. A change that passes on one platform and fails on
another isn't done.

## Making a change

Keep one logical change per commit, and make each commit pass
`composer verify` on its own, because anyone bisecting may land on it.
Move code in one commit and change it in another. Don't mix a reformat or
rename with a change of behaviour.

Tests come with the change, at the lowest level that shows it: a unit
test of the codec with the spec's bytes, a live test of a conversation
with a server. Cover normal cases, boundaries, and robustness cases with
the exact SQLSTATE and SQLCODE expected (R-TEST-2). Each test's doc
comment has a paragraph `Verifies:` that begins with its citation
(R-TRACE-1). A fixed defect gets a test that fails without the fix
(R-TEST-4).

Documents change in the same commit. Update CONFORMANCE.md for a row
implemented or changed (R-TRACE-2), RULES.md for a decision made or
changed, and TODO.md for open work done or found. Code never cites
TODO.md.

The manual changes in the same commit too (R-DOC-5). A change to what a
program sees updates each page of [doc/](doc/README.md) that states it,
with its examples run again and their output pasted: the User's Guide's
chapter (`doc/user/`), the class's page in the API Reference
(`doc/client/`), and the appendix that lists it (`doc/appendix/`). The
manual is laid out as turborel's, since turborel.com's documentation
site reads every repository's `doc/`: each page begins with its `# Title`
and a line `[Contents](README.md)`, and links between pages are relative
`.md` links.

Some rules deserve a reminder.

- Every failure is a `TurborelException` with its SQLSTATE and SQLCODE
  (R-ERR-1), and no dependency comes without a decision in RULES.md
  (R-DEP-1).
- Performance claims cite measurements: the workload, before and after,
  with the numbers, and the cost in memory.
- The coverage floor only rises (R-COV-2).

## Changes that touch the wire

These need more than passing tests. Say in the commit message what was
done for each that applies.

For the codec, test the bytes against the spec's: the worked example,
each type and value at its edges, and the malformed frames that must
fail the connection. A frame is never guessed at; it is read as the spec
says or the connection fails.

For connecting and authentication, run the live tests against a server
built from turborel's `main`, over TCP, TLS and a local socket, and say
which platforms were tried. A secret, a password, a SCRAM key or a
cancel key, never reaches a message or a trace.

For a behaviour turborel-client has, compare the driver's with
turborel-client's on the same server: the same values, the same
failures, the same SQLSTATEs.

## Commit messages

The form is Linux's.

```
area: summary in the imperative, at most 75 characters

The problem: what goes wrong, or is missing, and who it affects. A
wrong value, a lost connection, a crash or a refusal is said first,
with the script that shows it.

Why this change, and how it solves the problem. Mention the choices
made and the alternatives rejected, the rules and sections applied
(RULES.md D4, R-ERR-1, [PROTOCOL Frames]), and what was measured, with
numbers.

Fixes: 54a4f0239f2e ("codec: refuse a flag that is neither 0 nor 1")
Closes: <link to the issue>
Reported-by: A Reporter <reporter@example.org>
```

The subject is `area: summary`, at most 75 characters, in the imperative
("refuse a frame...", not "refused"), with no full stop. It says what
changes, isn't a file name, and isn't reused within a series.

The body is wrapped at 75 columns and written for someone reading `git log`
years later with no context. Say what the change does in plain prose, since
the diff shows how. Don't write "as discussed" or "v2".

Pick the area where most of the change is.

| Area | Covers |
|---|---|
| `codec` | `src/Internal/Codec/`: frames, fields, the tail, types and values, JSON, compression |
| `transport` | `src/Internal/Transport/`: TCP, Unix sockets, named pipes, timeouts |
| `auth` | `src/Internal/Auth/`: the mechanisms, SCRAM and its key cache |
| `tls` | `src/Internal/Tls/`: TLS, its modes, channel binding |
| `session` | `src/Session/`: Hello, Ready, Status, notices, cancel, terminate, ping, reset |
| `statements` | `src/Statements/`: queries, prepared statements, cursors, completions |
| `lobs` | `src/Lobs/`: large objects |
| `copy` | `src/Copy/`: copy, import, export |
| `settings` | `src/Settings/`: the URI, options, environment, service and password files, several hosts |
| `pipeline` | `src/Pipeline/` |
| `pool` | `src/Pool/` |
| `api` | `src/Pdo/`, the PDO-style API, and the package's root classes |
| `doc` | `doc/`, README.md, RULES.md, CONFORMANCE.md, TODO.md, VERSIONING.md, AGENTS.md, CLAUDE.md, this file |
| `ci` | `.github/`: workflows, issue forms, labels, Dependabot |
| `build` | composer.json and composer.lock, the tools' configurations, `tools/`, `.githooks/` |
| `treewide` | a mechanical change across many areas |

A layer's tests, under `tests/`, go with its area.

Trailers go at the end, one per line, never wrapped.

| Trailer | Meaning |
|---|---|
| `Fixes: <12+ hex digits> ("<subject>")` | the commit that introduced the defect fixed |
| `Closes: <link>` | the issue this commit resolves |
| `Link: <link>` | background, such as a discussion or an issue fixed only in part |
| `Reported-by:` | who reported the defect fixed, with `Closes:` |
| `Suggested-by:` | who suggested the idea |
| `Co-authored-by:` | a co-author, as GitHub credits one |
| `Reviewed-by:` | a reviewer who checked the change and is satisfied with it |
| `Acked-by:` | a maintainer of the area who approves it without a full review |
| `Tested-by:` | someone who tested it, saying where if that matters |

`Reported-by:` and `Suggested-by:` may be added without asking. The others
name someone who said so on the record. `git config core.abbrev 12` gives
12-digit hashes, and `git log -1 --pretty='Fixes: %h ("%s")' <commit>` writes
the `Fixes:` line.

A commit carries no AI or agent attribution: no `Co-Authored-By` for an
agent, no "Generated with", and no `Signed-off-by`. Whoever submits a
change answers for it (see [License](#license)).

Stage a commit by an explicit list of paths, `git add -- <path> ...`,
never `git add -A` or `git add .` on the whole tree, so nothing stray
goes in.

## Pull requests

A pull request is a patch series, and its description is the cover letter.
The template asks for what and why, the series commit by commit, testing,
compatibility and performance. Each commit message still stands alone.

Base it on `main` and keep it rebased, without merging `main` into it. When
revising, rewrite the commits so the series is again what should be merged,
with no "fix review comments" commits. Force-push, and say in a comment what
changed since the last version.

Trailers given in review go into the commits they cover. Drop them from a
commit that later changes substantially, and say so.

Keep a series to about 15 commits. Split a larger one into series that each
stand alone.

## Review

Reviewers check each commit against RULES.md §7's checklist, and that the
manual is updated (R-DOC-5). For
[changes that touch the wire](#changes-that-touch-the-wire), they also
check that the bytes, live and comparison checks were done. Review
comments are about the change, never the person. A reviewer who is
satisfied gives `Reviewed-by:`.

## Reporting issues

Use the issue forms, **Bug**, **Conformance**, **Performance** or
**Feature**. Try the latest `main` first, and add to an existing issue that
matches rather than opening another.

Reproduce with the smallest PHP script that shows it, and the SQL it
runs from an empty database, including the schema and data.

State the impact first. Put it in the title's first word when it is severe.

| Tag | Meaning |
|---|---|
| `[WRONG VALUE]` | a value read or sent wrongly, or data changed that should not be |
| `[CRASH]` | an uncaught error, a fatal error or a hang |
| `[REGRESSION]` | worked in an earlier commit (give the last good commit and, if you can, the first bad one) |

Give the driver's commit (`git rev-parse --short=12 HEAD`), the server's
version, `php -v`, and the platform and architecture. Say how the driver
connected: TCP, a Unix socket or a named pipe, TLS or not, and the
mechanism.

## Security

Don't open a public issue for a vulnerability. Report it privately, as
[SECURITY.md](SECURITY.md) says.

## License

turborel-php is under the BSD-2-Clause Plus Patent License in
[LICENSE](LICENSE), and a contribution is made under that license.
Contributors keep their copyright and own their changes. Whoever
submits a change answers for all of it, however it was written.
