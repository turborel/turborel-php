# Pipelines and Pools

[Contents](README.md)

A pipeline sends a batch of requests in one round trip; a pool shares a
bounded set of connections; the SQL-agent holds several named
connections. [Pipelines](../client/27-pipelines.md),
[Pools](../client/28-pools.md) and [The SQL-agent](../client/29-sql-agent.md)
list their methods. Each example runs on `$connection`, a connection
made as [Connecting](02-connecting.md) shows, as a user with a schema of
its own.

## Pipelines

`pipeline` gives a connection's pipeline; `query` and `execute` queue
requests; `run` writes them and gives one `Piped` outcome each, in
order: its `outcome`, its `failure`, or that it was skipped. The server
answers each request alone and in turn, so a failure is its request's
own, and the requests after it run.

```php
$connection->setAutocommit(true);
$connection->query('CREATE TABLE STAFF (ID INTEGER PRIMARY KEY, NAME VARCHAR(20))');
$insert = $connection->prepare('INSERT INTO STAFF VALUES (?, ?)');
$outcomes = $connection->pipeline()
    ->query("INSERT INTO STAFF VALUES (1, 'ANN')")
    ->execute($insert, [2, 'BOB'])
    ->execute($insert, [1, 'AGAIN'])
    ->query('SELECT NAME FROM STAFF ORDER BY ID')
    ->run();
foreach ($outcomes as $piped) {
    echo $piped->failure?->getSqlState() ?? implode(' ', array_column($piped->outcome->rows, 0)) ?: 'done', PHP_EOL;
}
```

```text
done
done
23000
ANN BOB
```

Requests are written a window of 64 KiB at a time, its replies read
before the next window is written, so a long batch never leaves client
and server each waiting for the other to read. Under autocommit each
request is its own transaction.

`onError(OnError::Skip)` makes a batch stop at its first failure, the
requests before it kept: a savepoint is set before each request but the
first, the requests after a failure are rolled back to the savepoint
set after it, and those of a window not yet written are never sent;
each is skipped. Such a batch is one transaction: under autocommit, of
a batch that began none, it is committed as the batch ends. `run` fails
if the connection is lost (SQLSTATE 08006), or if a skipping batch
cannot be undone.

## Pools

`Pool` keeps a bounded set of connections, made of `Settings`, a
`Source`, or a closure that makes one. `get` hands out an idle
connection that answers, or makes one while fewer than `max` are made,
or waits for one to come back. The `Pooled` it gives holds the
connection, `connection()`, until it is released, or let go of, when it
goes back to the pool.

```php
use Turborel\Pool\Pool;
use Turborel\Pool\PoolConfig;
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;

$pool = new Pool(static fn(): Connection => Connection::connect(Target::tcp('127.0.0.1'), 'ann', new Trust()), new PoolConfig(['max' => 2]));
$borrowed = $pool->get();
$borrowed->connection()->query("SET SESSION.TIME_ZONE = '+02:00'");
$borrowed->release();
$again = $pool->get();
[$made, $idle] = $pool->counts();
echo $again->connection()->setting('SESSION.TIME_ZONE'), "; {$made} made, {$idle} idle", PHP_EOL;
$again->release();
```

```text
+00:00; 1 made, 0 idle
```

A connection comes back reset to the session it logged in with: by the
protocol's Reset, its transaction ended and its prepared statements,
cursors and settings gone, its user kept; autocommit off; then the
statements `reset` names. Its check, as it is handed out again, is a
Ping. Of a server that takes neither, the check is ROLLBACK and the
reset one pipeline. A connection lost, failing its reset, past its
lifetime, or beyond `maxIdle` is closed; one idle past `idleTimeout`, or
failing its check, is closed as `get` meets it, and another taken or
made. `get` fails with SQLSTATE 08001 if none comes back within `wait`.
The pool starts no thread. PHP runs one thread, so a connection comes
back only as the program releases one: a `get` within a Fiber that finds
none suspends its Fiber while it waits, so another may release one;
outside a Fiber, it waits `wait` and fails.

## The SQL-agent

`Agent` holds a program's connections, each named, one current, and
runs CONNECT, SET CONNECTION and DISCONNECT itself, as SQL-92's
SQL-agent does; every other statement goes to the current connection. A
`Connector` the program gives makes the connections CONNECT asks for, to
the address it names, as the user it names.
