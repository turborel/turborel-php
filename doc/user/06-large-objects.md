# Large Objects

[Contents](README.md)

A CHARACTER LARGE OBJECT or BINARY LARGE OBJECT value of at most
SERVER.LOB_INLINE_BYTES, 64 KiB unless the operator sets otherwise,
comes in its row whole, as a `Turborel\Value\Lob` of its `kind` and
`octets`. A longer one comes as a `Turborel\Value\LobLocator`, which
holds its locator and its lengths, `octets` and `characters`, not its
value. [Large Objects](../client/24-large-objects.md) lists the methods.
Each example runs on `$connection`, a connection made as
[Connecting](02-connecting.md) shows, as a user with a schema of its own.

## Reading

`readLob` reads a range of a value, by octets from 0, or by a character
large object's whole characters, at most 256 KiB at a time, of a value
held whole too. `lobReader` gives a stream of the whole value, read a
chunk at a time, that PHP's file functions read.

```php
use Turborel\Value\Binary;

$connection->query('CREATE TABLE SCANS (ID INTEGER, IMAGE BLOB(1M), NOTE CLOB(1K))');
$insert = $connection->prepare('INSERT INTO SCANS VALUES (?, ?, ?)');
$connection->execute($insert, [1, new Binary(str_repeat("\x07", 100000)), 'café']);
$connection->query('COMMIT');
[$image, $note] = $connection->query('SELECT IMAGE, NOTE FROM SCANS')->rows[0];
echo get_class($image), ' of ', $image->octets, ' octets; ', get_class($note), PHP_EOL;
echo strlen(stream_get_contents($connection->lobReader($image))), ' octets read; ', $connection->readLob($note, 2, 2, true), PHP_EOL;
$connection->query('COMMIT');
```

```text
Turborel\Value\LobLocator of 100000 octets; Turborel\Value\Lob
100000 octets read; fé
```

A locator is valid until the transaction that read it ends. A statement
whose reply sent one keeps its transaction open, under autocommit too,
so commit once the value is read. `freeLob` ends a locator sooner, and
`holdLob` keeps it past the commit, until it is freed or a rollback ends
it. A locator no longer valid fails with SQLSTATE 0F001.

## Writing

`createLob` makes a value written a piece at a time: its writer's
`write` takes each piece, sent to the server a chunk at a time, and
`finish` gives the value as a `LobLocator` to pass as a parameter of a
CLOB or BLOB. A character large object's octets are UTF-8, as the
server checks at each Append: one that is not is refused, SQLSTATE
22021, with every Append after it, and one that ends within a character
is refused where it is used. A
character may be split between writes.

```php
use Turborel\Value\LobKind;

$connection->query('CREATE TABLE NOTES (BODY CLOB(1M))');
$writer = $connection->createLob(LobKind::Character);
$writer->write("caf\xC3");
$writer->write("\xA9" . str_repeat('!', 99999));
$body = $writer->finish();
$connection->execute($connection->prepare('INSERT INTO NOTES VALUES (?)'), [$body]);
echo $body->characters, ' characters, ', $body->octets, ' octets', PHP_EOL;
$connection->query('COMMIT');
```

```text
100003 characters, 100004 octets
```
