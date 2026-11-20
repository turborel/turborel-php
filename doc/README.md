# The turborel-php Documentation Set

turborel-php is the PHP driver for turborel, a relational database
system. It connects a PHP program to a turborel server, `turboreld`, over
turborel's native protocol, written in plain PHP with no C extension, and
gives it an API in PDO's shape. This set of manuals describes it for
those who write programs with it.

turborel-php is in alpha: what these manuals describe may change in any
release until 1.0.0 ([Versions](appendix/d-versions.md)). Each change
updates the pages it touches, so the manuals describe the release they
come with. A chapter is written as the milestone that builds what it
describes lands ([TODO.md](../TODO.md)); until then it says what it will
cover.

turborel's own manuals describe the server, its SQL and its protocol:
the Operator's Guide for running `turboreld`, the SQL Reference Manual,
and the Client Reference, whose *The Native Protocol* is the spec this
driver follows.

## The volumes

| Volume | For | |
|---|---|---|
| [I. User's Guide](user/README.md) | Anyone who writes a PHP program against turborel | Installing, connecting, statements, transactions, large objects, copying, pipelines and pools, settings, errors, chapter by chapter |
| [II. API Reference](client/README.md) | The same, looking something up | The driver's classes, a page each: the PDO-style API, its error type, the low-level client |
| [Appendices](appendix/README.md) | Everyone | Types, errors, settings, versions |

Read the User's Guide from the start to learn the driver; turn to the API
Reference and the appendices to look a thing up.

## Conventions

**Examples** are set in a fixed-width font. PHP is written as PER Coding
Style has it, with strict types declared; SQL key words are in upper
case, as turborel's manuals write them:

```php
$staff = $db->prepare('SELECT NAME FROM STAFF WHERE ID = ?');
```

**Results** of examples follow them, as the program prints them. Every
example is run before it is printed.

**Citations** name the section of turborel's protocol spec a behaviour
comes from: [PROTOCOL Frames] is the section *Frames* of *The Native
Protocol*; [CLIENT Pipelines] is the section *Pipelines* of turborel's
*Settings, Pipelines and Pools*, which describes turborel-client, whose
behaviour the driver matches.

**Errors** are given by SQLSTATE, the code the SQL standard defines, and
SQLCODE, the older number. Every failure is a `Turborel\TurborelException`
carrying both; [Appendix B](appendix/b-errors.md) lists them.
