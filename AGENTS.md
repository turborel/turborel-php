# Guide for coding agents

How to work on turborel-php as a coding agent, with any tool. If this
file disagrees with [RULES.md](RULES.md) or
[CONTRIBUTING.md](CONTRIBUTING.md), follow those.

turborel-php is the PHP driver for turborel, a relational database
system. It speaks turborel's native protocol, version 1, in plain PHP,
and matches turborel-client, turborel's client library in Rust, feature
for feature. See [README.md](README.md).

## Before you change anything

Read RULES.md §3 (code), §4 (tests), §7 (process) and the §1 decisions
for your area. Read CONTRIBUTING.md for how a change is made and the
extra checks for changes that touch the wire. Find the rows of
CONFORMANCE.md your change implements, and the milestone in TODO.md they
belong to.

## Build and check

Every change must pass `composer verify` and `composer coverage` before
it is handed back.

```
composer install                 # the development tools, as composer.lock pins them
composer verify                  # every check a change must pass
composer coverage                # the tests with coverage, and the floor (pcov or Xdebug)
composer install-hooks           # run verify before every commit (once per clone)
composer format                  # format the code to PER Coding Style 3.0
composer format-check            # check the format alone
composer check                   # sizes, comments, doc comments, test citations alone
composer analyse                 # PHPStan alone
composer test                    # the tests alone
```

`verify` stops at the first failure. CI runs it on Linux, macOS and
Windows on PHP 8.3, 8.4 and 8.5, and coverage on Linux (D3, D9). The
coverage floor is in `tools/CoverageFloor.php` and only rises (R-COV-2).

Neither pcov nor Xdebug ships with PHP. To run coverage without
installing either, build pcov from PECL's source (`pecl download pcov`,
then `phpize`, `./configure` and `make` in its own directory), write an
ini file of `extension=/path/to/modules/pcov.so` and `pcov.enabled=1` in
a directory of its own, and name that directory for the run alone:
`PHP_INI_SCAN_DIR=":/that/directory" composer coverage`. The leading
colon keeps PHP's own ini files.

Project automation is PHP, in composer.json's scripts and `tools/`, never
shell scripts (D5, R-TOOL-1).

## Where things are

| Path | What |
|---|---|
| `src/Turborel.php` | the package's root: its version, the protocol's version |
| `src/TurborelException.php` | the driver's one error type (R-ERR-1) |
| `src/Internal/` | internals, `@internal` (D12): `Codec/`, and later `Transport/`, `Auth/`, `Tls/` |
| `src/Internal/Codec/FrameHeader.php` | a frame's header, encoded and decoded |
| `tests/Unit/` | tests with no network, mirroring `src/` |
| `tests/Live/` | tests against a server on loopback, from milestone M2 |
| `tools/` | the project's own checks (D5), run by `tools/run.php` |
| `doc/` | the manual: `user/`, `client/`, `appendix/` |

The protocol's spec is turborel's `doc/client/05-protocol.md`; the
client's features are its `doc/client/02-library.md` and
`02-library-connections.md`; the reference code is turborel's
`client/src/` and `protocol/src/`. Where the spec and the code differ,
the code is right.

## Rules that are broken most often

`verify` treats every warning as an error. RULES.md §3 and §4 have the
full list.

- Every file declares `strict_types=1` (R-STYLE-2).
- Every failure is a `TurborelException` with its SQLSTATE and SQLCODE;
  no other exception leaves the library, and no `exit`, `die`,
  `trigger_error` or `@` (R-ERR-1, R-ERR-3). A function that can raise
  one says `@throws TurborelException`; PHPStan checks it (D7).
- A failure the driver finds takes turborel-client's codes, as 08006 and
  -919 for a connection lost (R-ERR-2).
- No secret in a message or a trace: `#[\SensitiveParameter]` (R-ERR-4).
- No integer past 64 bits, and no exact number, as a float (R-NUM-1).
- Keep functions to 50 lines of code and 5 parameters, and files to 600
  lines (R-SIZE).
- No magic numbers or strings; the protocol's tags are named typed
  constants, defined once (R-CONST).
- No printing, superglobals or ini changes in the library (R-STYLE-4).
- Every class and every public and protected member has a PHPDoc comment
  (R-DOC-1). Comments say why (R-DOC-2). No TODO, FIXME or XXX comments
  or commented-out code (R-DOC-3).
- No `@phpstan-ignore` without the identifier and the reason, and no
  baseline (R-DEV-1).
- No new dependency without a decision in RULES.md (R-DEP-1).
- Internals go under `Turborel\Internal`, marked `@internal` (D12).

## Tests

Every test has a doc comment with a paragraph `Verifies:` beginning with
a citation `composer check` accepts (R-TRACE-1): `[PROTOCOL <heading>]`
of 05-protocol.md, `[CLIENT <heading>]` of the client manual,
`[DESIGN] D<n>` or `[TOOL] R-…`. The headings accepted are in
`tools/Headings.php`; a heading turborel's manual changes is changed
there.

Tests are deterministic, with no clock, randomness or network beyond
loopback, and run in their written order (R-TEST-3). Each test class
names what it covers with `#[CoversClass]` (D8). Fixtures shared by test
classes go in `tests/Support` (R-TEST-6). A fixed defect gets a
regression test (R-TEST-4).

## Citing the spec

Check every citation against the spec's text before writing it. Never
cite from memory. Cite a section by its heading exactly, as
`[PROTOCOL Hello 'H']`. Code never cites TODO.md, and `composer check`
refuses it in a comment (R-DOC-3).

## Documents

A finished feature updates these in the same change:

- RULES.md, if it made a decision, including the row in §1's table
- CONFORMANCE.md, for each row implemented or changed (R-TRACE-2)
- TODO.md, which only holds open work
- [doc/](doc/README.md), for anything a program sees (R-DOC-5)
- README.md, if what the driver does in short has changed

The manual is three volumes, laid out as turborel's, since
turborel.com's documentation site reads `doc/` and builds its pages from
it: Volume I, the User's Guide (`doc/user/`), a chapter a subject;
Volume II, the API Reference (`doc/client/`), a page a main class; and
the Appendices (`doc/appendix/`): types, errors, settings, versions.
Each page begins with its `# Title`, then a line `[Contents](README.md)`;
links between pages are relative `.md` links, and every one must
resolve. A chapter not yet written says what it will cover and the
milestone that fills it; the milestone replaces that with the chapter.

For the manual, check each claim against the code, and run every example
and paste its real output. No benchmarks or timings go in the documents.

## Commits

- Commit only when the maintainer asks, and never push without approval.
- Follow CONTRIBUTING.md's form, with one logical change per commit.
- Add no AI or agent attribution to a commit, a pull request, a file or
  the package. That means no `Co-Authored-By` for an agent, no
  "Generated with" and no `Signed-off-by`.
- Stage with an explicit path list, `git add -- <path> ...`. Never use
  `git add -A` or `git add .` on the whole tree.
- Never stage `LICENSE` or scratch files.
- What one platform alone has, as a named pipe on Windows, can't be
  verified locally on another. Say so when handing it back.
