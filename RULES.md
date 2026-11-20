# turborel-php rules

How turborel-php is designed, written and verified. Every change follows
these rules; `composer verify` checks those a tool can check, and review
checks the rest.

Rules (R-…) say how code and tests are written. **shall** is mandatory,
and a departure needs a recorded deviation (§6). **should** is expected,
and a reviewer may accept a reasoned exception.

Decisions (D1…) record how the driver is built and why. Change one only by
changing this file in a reviewed commit. A decision says what was chosen
and why, and the class it names holds the mechanism.

Cite both by ID in code, tests, reviews and commit messages, as in
`RULES.md D2` or `R-ERR-1`.

Before changing code, read §3 (code rules), §4 (test rules) and the
decisions covering your area. What the driver implements of the protocol
and of turborel-client is in [CONFORMANCE.md](CONFORMANCE.md), and open
work is in [TODO.md](TODO.md).

These rules follow turborel's own RULES.md, adapted to PHP. Where PHP's
library conventions and a rule of the project differ, the rule is kept,
and the decision that keeps it says so (D10, D11).

[1 Decisions](#1-decisions) · [2 Layout](#2-project-layout) ·
[3 Code](#3-code-rules) · [4 Tests](#4-test-rules) ·
[5 Coverage](#5-coverage) · [6 Deviations](#6-deviations) ·
[7 Process](#7-process-and-tools) · [8 References](#8-references)

---

## 1. Decisions

| # | Decision |
|---|---|
| **The driver** | |
| D1 | The native protocol, version 1, in plain PHP, with no native core |
| D2 | The standard API is a PDO-style API written in PHP |
| D3 | PHP 8.3 or later, 64-bit |
| D4 | No dependency at run time but PHP's own extensions |
| D12 | Internals live under `Turborel\Internal` |
| D13 | The driver's version is the turborel release it is made for |
| **Tooling and process** | |
| D5 | Automation in PHP: Composer's scripts and `tools/` |
| D6 | Formatting by PHP-CS-Fixer, to PER Coding Style 3.0 |
| D7 | Static analysis by PHPStan at its highest level, with strict rules |
| D8 | Tests by PHPUnit, strict |
| D9 | Coverage by pcov or Xdebug, with a floor |
| D10 | composer.lock is committed, resolved for PHP 8.3 |
| D11 | composer.json carries the version |

### The driver

#### D1. The native protocol, version 1, in plain PHP, with no native core

turborel-php speaks turborel's native protocol itself, version 1, as
turborel's `doc/client/05-protocol.md` describes it, over PHP's streams:
TCP, Unix sockets and Windows named pipes, and TLS by the openssl
extension. It wraps no library of another language and needs no
extension of its own, so it installs from Packagist with Composer alone,
on every platform PHP runs on.

What it does is what turborel-client, turborel's reference client in
Rust, does: its features, its defaults, its SQLSTATEs. Where the spec and
turborel-client's code differ, the code is right, and the difference is
reported to turborel. The spec and the client are read at turborel's
`main`; tests cite the spec's sections by their headings (R-TRACE-1).

#### D2. The standard API is a PDO-style API written in PHP

PHP's standard database interface is PDO. A real PDO driver is a C
extension, built against each PHP release and platform and installed
outside Composer, which D1 rules out. So turborel-php gives an API in
PDO's shape, written in PHP: a connection made from a DSN or the
driver's settings, prepared statements with `?` parameters, fetch modes,
`beginTransaction`, `commit` and `rollBack`, and errors raised as
exceptions carrying the SQLSTATE and the SQLCODE (R-ERR-1). It is not
`\PDO`, and a class that requires a `\PDO` instance cannot take it; it is
userland, and says so in its documentation.

The API stands on a lower-level client with turborel-client's features
(CONFORMANCE.md), and is built in milestone M7 (TODO.md), once that
client is complete.

#### D3. PHP 8.3 or later, 64-bit

PHP supports each release for two years and gives it security fixes for
two more. The driver supports every release PHP still supports, and drops
each at its end of life. PHP 8.2's security support ends on 2026-12-31,
and Laravel 13, the framework most of PHP's applications run on, needs
8.3, so the driver needs 8.3. CI tests it on 8.3, 8.4 and 8.5.

8.3 also gives the language what the rules lean on: typed class
constants (R-CONST-1) and `#[\Override]`.

The driver needs a 64-bit PHP, where an `int` holds 64 bits: a frame's
length is a u32 and the protocol has u64 and i64 fields, which a 32-bit
`int` cannot hold. PHP's builds for Linux, macOS and Windows are 64-bit.

#### D4. No dependency at run time but PHP's own extensions

composer.json requires nothing at run time but PHP and ext-openssl, which
TLS and SCRAM-SHA-256-PLUS's channel binding need. It suggests ext-zlib,
for compressed frames, and ext-bcmath, for arithmetic on exact numbers.
Every one is an extension PHP itself ships.

An exact number is given to a program as a string, its digits and its
point, since the protocol carries 128-bit units and a scale and no PHP
type holds them all. Where the driver computes with one, it uses bcmath
if present, and otherwise works on the text, giving the same strings
either way. A number is never given as a float.

#### D12. Internals live under `Turborel\Internal`

The codec, transport, authentication and TLS, and every class a program
does not use itself, live under `Turborel\Internal` and are marked
`@internal`. Nothing there is part of the public API, and it may change
in any release. The public API is the namespace's other classes.

#### D13. The driver's version is the turborel release it is made for

A turborel release and each driver's release share one number: the
driver made for turborel 1.4.0 is 1.4.0. The driver has no version scheme
of its own. Its alpha is 0.0.0, as turborel's is, and speaks protocol
version 1. The number is composer.json's `version` (D11) and
`Turborel\Turborel::VERSION`; VERSIONING.md says how it is released.

### Tooling and process

#### D5. Automation in PHP: Composer's scripts and `tools/`

Every check runs as a Composer script, never a shell script, so it runs
the same on every platform. What no standard tool checks, `tools/` does,
in PHP that follows these rules: file and function sizes (R-SIZE),
comments left as open work (R-DOC-3), doc comments (R-DOC-1), test
citations (R-TRACE-1), the documents' relative links (R-DOC-5) and the
coverage floor (R-COV-2). The pre-commit
hook only hands over to `composer verify`, as git runs hooks as scripts.

#### D6. Formatting by PHP-CS-Fixer, to PER Coding Style 3.0

The code is formatted to PER Coding Style 3.0, the PHP-FIG's coding
style, which extends PSR-12. PHP-CS-Fixer applies it by its named rule
sets `@PER-CS3.0` and `@PER-CS3.0:risky`, pinned to that revision, so
a new revision of PER changes nothing until it is chosen here, and it
fixes what it finds as well as reporting it, where PHP_CodeSniffer was
the other choice. Beyond PER, the configuration
declares strict types in every file and asks for strict comparisons and
parameters (`.php-cs-fixer.dist.php`).

#### D7. Static analysis by PHPStan at its highest level, with strict rules

PHPStan runs at level `max`, with phpstan-strict-rules, its PHPUnit rules
and its deprecation rules, over `src/`, `tests/` and `tools/`, for every
PHP from 8.3 to 8.5. `TurborelException` is a checked exception: a
function that can raise one says so with `@throws`, and its callers catch
it or say so too, so no failure goes unhandled unseen (R-ERR-1). The
settings are in `phpstan.neon.dist`.

#### D8. Tests by PHPUnit, strict

PHPUnit 12, the newest major release that runs on PHP 8.3. A warning, a
notice, a deprecation or a risky test fails the run. Each test class
names the classes it covers with `#[CoversClass]`, and code it runs
outside them makes it risky. Tests run in their written order, never a
random one (R-TEST-3). The settings are in `phpunit.xml.dist`.

#### D9. Coverage by pcov or Xdebug, with a floor

`composer coverage` runs the tests with coverage of `src/` and checks
the share of lines covered against the floor in
`tools/CoverageFloor.php`, 95 % (R-COV-2). It needs pcov or Xdebug; CI
uses pcov. Neither ships with PHP. pcov builds from PECL's source with
`phpize`, and is loaded for one run by an ini file named through
`PHP_INI_SCAN_DIR`, with no change to the PHP installed (AGENTS.md).

#### D10. composer.lock is committed, resolved for PHP 8.3

A library's composer.lock is never read by the programs that install it,
and PHP's convention has been to leave it out. It is committed here all
the same, as turborel commits Cargo.lock, so every run of the checks uses
the same tools. `config.platform.php` is 8.3.0, so the lock holds
releases that run on every PHP the driver supports.

#### D11. composer.json carries the version

Composer advises a package published on Packagist to leave `version` out
and take it from its git tag. The driver's composer.json carries it, as
every turborel package's manifest does (D13), so the number is written in
the repository. A release's tag gives the same number. `composer
validate` runs with `--no-check-version`, which accepts the field.

---

## 2. Project layout

```
composer.json           the package: name, version, PHP, extensions,
                        autoloading, development tools, scripts (D5)
composer.lock           the development tools' versions (D10)
phpstan.neon.dist       static analysis (D7)
phpunit.xml.dist        the tests (D8)
.php-cs-fixer.dist.php  formatting (D6)
README.md               what the driver is; installing; a first query
RULES.md                this file
CONFORMANCE.md          the parity matrix: the protocol and turborel-client
TODO.md                 open work, by milestone
VERSIONING.md           the version, and how a release is published
CONTRIBUTING.md         changes, commit messages, pull requests, issues
SECURITY.md             how to report a vulnerability
TRADEMARK.md            the name and logos
AGENTS.md               the guide for coding agents
CLAUDE.md               points Claude Code to AGENTS.md
.github/                CI (workflows/), issue forms, labels, pull
                        request template, Dependabot
.githooks/pre-commit    runs `composer verify` before a commit
src/                    the library, namespace Turborel, by PSR-4
  Turborel.php          the package's root: its version
  TurborelException.php the driver's one error type (R-ERR-1)
  Internal/             internals (D12), by layer: Codec/, Transport/,
                        Auth/, Tls/, and the internals of the layers below
  Session/ Statements/ Lobs/ Copy/ Settings/ Pipeline/ Pool/
                        the client's public layers, as they are built
  Pdo/                  the PDO-style API (D2)
tests/                  the tests, namespace Turborel\Tests
  Unit/                 no network; mirrors src/
  Live/                 against a server on loopback, from milestone M2
tools/                  the project's own checks (D5), namespace
                        Turborel\Tools
doc/                    the manual (R-DOC-5), as turborel's lays it out
  README.md             the documentation set
  user/                 Volume I, the User's Guide
  client/               Volume II, the API Reference
  appendix/             the Appendices
artwork/                the logos
```

---

## 3. Code rules

### R-STYLE: formatting and idiom

| ID | Rule | Checked by |
|---|---|---|
| R-STYLE-1 | Code **shall** be formatted to PER Coding Style 3.0 (D6). | `composer format-check` |
| R-STYLE-2 | Every file **shall** declare `strict_types=1`. | PHP-CS-Fixer `declare_strict_types` |
| R-STYLE-3 | One class, interface, trait or enum a file, named as its path by PSR-4; names in PER's casing. | `composer check`; review |
| R-STYLE-4 | Library code **shall not** print, `var_dump`, read a superglobal or change an ini setting. | review |
| R-STYLE-5 | Comparisons **shall** be strict: `===`, `!==`, and `in_array` with `true`. | PHP-CS-Fixer; PHPStan |

### R-LINT: analysis and warnings

| ID | Rule | Checked by |
|---|---|---|
| R-LINT-1 | Code **shall** pass PHPStan at level `max` with its strict rules (D7), with no baseline. | `composer analyse` |
| R-LINT-2 | Warnings are errors: PHPStan's, PHPUnit's and PHP's own. | `composer verify` |
| R-LINT-3 | Every parameter, property and return **shall** have a native type; arrays and closures say their shapes in PHPDoc. | PHPStan |

### R-SIZE: short functions and files

| ID | Rule | Checked by |
|---|---|---|
| R-SIZE-1 | A function **shall** have at most 50 lines of code. | `composer check` |
| R-SIZE-2 | A source file **shall** have at most 600 lines. | `composer check` |
| R-SIZE-3 | A function **shall** take at most 5 parameters; else a value object. | `composer check` |
| R-SIZE-4 | A class **should** hold one concept, stated in its doc comment. | review |

### R-DOC: comments and documentation

| ID | Rule | Checked by |
|---|---|---|
| R-DOC-1 | Every class, interface, trait and enum, and every public and protected constant, property and method, **shall** have a PHPDoc comment, with `@throws` for each exception it raises. | `composer check`; PHPStan |
| R-DOC-2 | Comments **shall** say *why*: the section implemented, the choice made, the invariant relied on. | review |
| R-DOC-3 | No commented-out code, and no TODO, FIXME or XXX comment: open work goes in TODO.md, and code never cites TODO.md. | `composer check`; review |
| R-DOC-4 | Behaviour the spec governs **shall** cite its section by heading, `[PROTOCOL Frames]`; behaviour matching turborel-client, `[CLIENT Pipelines]`; the driver's own choices, `[DESIGN]` and the decision. | review |
| R-DOC-5 | A change a program can see **shall** update the manual, `doc/`, in the same commit, with its examples run. The manual keeps turborel's layout, which turborel.com's documentation site reads: each page its `# Title`, then `[Contents](README.md)`; relative `.md` links, each resolving. | review; `composer check` (links) |

### R-CONST: no magic numbers or strings

| ID | Rule | Checked by |
|---|---|---|
| R-CONST-1 | Numeric literals other than `0` or `1` **shall** be typed class constants, documenting the value's source. | review |
| R-CONST-2 | The protocol's tags **shall** be named constants, each defined once: message kinds, data type and value tags, tail tags, capability, mechanism and status names. | review |
| R-CONST-3 | Related constants **shall** live together, in the class of their concept. | review |
| R-CONST-4 | Tests may use literal fixtures, such as the spec's bytes; an expected value restating a constant **should** use it. | review |

### R-ERR: errors

| ID | Rule | Checked by |
|---|---|---|
| R-ERR-1 | Every failure the driver raises **shall** be a `TurborelException`, carrying the SQLCODE, the SQLSTATE, the message and the rest of the failure: the rule cited, the position, whether a transaction is active, and the diagnostics. No other exception or error leaves the library, but PHP's own `TypeError` for an argument of the wrong type, the caller's error; no `exit`, `die` or `trigger_error`. | PHPStan's checked exceptions; review |
| R-ERR-2 | A failure the server reports keeps its codes unchanged. A failure the driver finds takes the codes turborel-client gives it, as SQLSTATE 08006 and SQLCODE -919 for a connection lost. | tests |
| R-ERR-3 | A PHP function that reports failure by its return value **shall** have it checked; no `@` operator. | PHPStan; review |
| R-ERR-4 | A password, a SCRAM key or a cancel key **shall** never appear in a message, a trace or a dump: parameters holding one are `#[\SensitiveParameter]`. | review |

### R-NUM: numbers

| ID | Rule | Checked by |
|---|---|---|
| R-NUM-1 | A value outside a 64-bit `int`, an i128 or a u64 past `PHP_INT_MAX`, **shall** be held as a string, never as a float (D4). | review; tests |
| R-NUM-2 | Floats **shall not** be compared for equality, except as the spec's values require: NaN and -0.0 kept bit for bit. | review |

### R-SAFE: resources and the language

| ID | Rule | Checked by |
|---|---|---|
| R-SAFE-1 | No `eval`, `extract`, variable variables, `unserialize` of what a peer sent, or global mutable state. | review |
| R-SAFE-2 | Memory **shall** be bounded by what was asked for: a length or a count a peer sends cannot make the driver reserve more than the frame it read holds. | tests |
| R-SAFE-3 | TLS **shall** verify the server as its mode asks, and never less without saying so. | tests |

### R-DEP: dependencies

| ID | Rule | Checked by |
|---|---|---|
| R-DEP-1 | A dependency at run time **shall** have a decision in §1 giving its reason, version, license and review, and be production-grade by its own claims. PHP and its own extensions come first (D4). | review |
| R-DEP-2 | Development tools are `require-dev`, at the versions composer.lock holds (D10). | review |

### R-PLAT: platforms

| ID | Rule | Checked by |
|---|---|---|
| R-PLAT-1 | The driver **shall** work on Linux, macOS and Windows, 64-bit (D3). What one platform alone has, as a Unix socket or a named pipe, is refused clearly elsewhere. | CI |

---

## 4. Test rules

### R-TEST: levels and layout

| ID | Rule | Checked by |
|---|---|---|
| R-TEST-1 | Tests **shall** be PHPUnit's (D8): `tests/Unit` with no network, and `tests/Live` against a server on loopback. | review |
| R-TEST-2 | Tests **shall** derive from the spec and turborel-client, not the code: normal cases with their boundaries, and robustness cases with the exact SQLSTATE and SQLCODE expected. | review |
| R-TEST-3 | Tests **shall** be deterministic and independent: no clock, randomness or network beyond loopback, run in their written order. | review |
| R-TEST-4 | Every fixed defect **shall** get a regression test that fails without the fix, its doc comment saying so. | review |
| R-TEST-5 | A test's name **shall** say the behaviour checked; a test class names what it covers (`#[CoversClass]`). | PHPUnit |
| R-TEST-6 | A fixture or helper shared by test classes **shall** be written once, in `tests/Support`. | review |

### R-TRACE: traceability

| ID | Rule | Checked by |
|---|---|---|
| R-TRACE-1 | Every test **shall** have a doc comment with a paragraph `Verifies:` beginning with a citation: `[PROTOCOL <heading>]` of turborel's `doc/client/05-protocol.md`; `[CLIENT <heading>]` of `doc/client/02-library.md` or `02-library-connections.md`; `[DESIGN] D<n>`; or `[TOOL] R-…`. Every citation names a heading or a rule that exists. | `composer check` |
| R-TRACE-2 | CONFORMANCE.md **shall** change with the change implementing a row of it. | review |

---

## 5. Coverage

| ID | Rule | Checked by |
|---|---|---|
| R-COV-1 | Coverage **shall** be measured by `composer coverage`, over `src/`, by pcov or Xdebug (D9). | `composer coverage` |
| R-COV-2 | Line coverage **shall not** fall below the floor in `tools/CoverageFloor.php`; a change raising it **should** raise the floor, which never falls. | `composer coverage`, in CI |

---

## 6. Deviations

| ID | Rule | Checked by |
|---|---|---|
| R-DEV-1 | A `@phpstan-ignore` **shall** name the error's identifier and, in a comment, the rule departed from and why. There is no baseline. | review |
| R-DEV-2 | Permanent deviations: `tools/` prints and gives an exit status, as a command does. | review |

---

## 7. Process and tools

| ID | Rule | Checked by |
|---|---|---|
| R-PROC-1 | `composer verify` **shall** pass before every commit: composer.json validated, formatting, the project's checks, static analysis, every unit test. CI runs it on each platform and PHP of D3, and `composer coverage` too. | pre-commit hook; CI |
| R-PROC-2 | Every change **shall** be reviewed against the checklist below. | review |
| R-PROC-3 | Commits **should** be small and single-purpose, in CONTRIBUTING.md's form. | review |
| R-TOOL-1 | Project automation **shall** be PHP, in Composer's scripts and `tools/`, never shell scripts (D5). | review |
| R-TOOL-2 | `tools/` **shall** follow these rules, and is analysed and checked with the library. | `composer verify` |

The review checklist:

- the spec's sections cited in code (R-DOC-4) and tests (R-TRACE-1);
- normal and robustness tests (R-TEST-2);
- every failure a `TurborelException` with its codes (R-ERR-1);
- no magic numbers or strings (R-CONST);
- comments explain why (R-DOC-2);
- CONFORMANCE.md updated (R-TRACE-2), TODO.md where open work changed,
  and doc/ where a program sees the change (R-DOC-5).

---

## 8. References

- turborel, `doc/client/05-protocol.md`, *The Native Protocol*, version
  1; `doc/client/02-library.md` and `02-library-connections.md`, the
  client library's features; turborel's RULES.md, which these rules adapt.
- PHP-FIG, *PSR-4: Autoloader*; *PER Coding Style 3.0*.
- PHP's documentation, *Supported Versions*.
- Composer's documentation, *The composer.json schema* and *Libraries*.
- RFC 5802 and RFC 7677, SCRAM and SCRAM-SHA-256; RFC 5929 and RFC 9266,
  TLS channel bindings.
