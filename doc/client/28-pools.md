# Pools

[Contents](README.md)

## NAME

`Turborel\Pool\Pool`: a bounded set of connections a program shares.

## SYNOPSIS

```php
final class Pool
{
    public function __construct(Settings|Source|\Closure $source, ?PoolConfig $config = null);
    public function get(): Pooled;
    public function counts(): array;
}

final class Pooled
{
    public function connection(): Connection;
    public function release(): void;
}

final readonly class PoolConfig
{
    public function __construct(array $parts = []);
}

interface Source
{
    public function connect(): Connection;
}
```

`PoolConfig` takes its parts by name, each as Appendix C lists them:
`max`, `maxIdle`, `idleTimeout`, `maxLifetime` and `wait`, in seconds,
`check` and `reset`.

## DESCRIPTION

A pool makes its connections of `Settings`, a `Source`, or a closure.
`get` hands out an idle connection that answers its check, else makes
one while fewer than `max` are made, else waits for one to come back, at
most `wait`. `counts` gives how many are made, handed out and idle, and
how many of them are idle. Clones of a pool are the same pool.

A `Pooled` gives its connection by `connection` until `release`, or
until it is let go of, gives it back: reset by the protocol's Reset,
autocommit off, then the `reset` statements; of a server that takes no
Reset, by one pipeline of ROLLBACK, the Close and Deallocate of each
statement prepared, SET SESSION AUTHORIZATION and SET SCHEMA of the
login's user, SET TIME ZONE LOCAL, then the `reset` statements. The
check is a Ping, or ROLLBACK of a server that takes none; each waits at
most 30 seconds. A connection lost, failing its reset, past
`maxLifetime`, or beyond `maxIdle` is closed; one idle past
`idleTimeout`, or failing its check, is closed as `get` meets it.

The pool starts no thread. A `get` that waits within a Fiber suspends
its Fiber, so another may release a connection; outside one, it sleeps
a moment at a time until `wait` runs out.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08001 | -919 | No connection came back within `wait` |
| TB910 | -910 | A `Pooled`'s connection asked for once it is given back |
| | | The source's failure to make a connection |

## EXAMPLES

```php
use Turborel\Pool\Pool;
use Turborel\Pool\PoolConfig;
use Turborel\Settings\Options;
use Turborel\Settings\Settings;

$settings = Settings::resolveIn(Options::fromUri('turborel://ann@127.0.0.1:7422/?tls_mode=disable'), static fn(string $name): ?string => null);
$pool = new Pool($settings, new PoolConfig(['max' => 1, 'wait' => 0.1]));
$held = $pool->get();
try {
    $pool->get();
} catch (Turborel\TurborelException $none) {
    echo $none->getSqlState(), ': ', $none->getMessage(), PHP_EOL;
}
$held->release();
```

```text
08001: no connection of the pool's 1 came back within 100ms
```

## STANDARD

[CLIENT Pools].

## SEE ALSO

[Settings](26-settings.md), [Pipelines](27-pipelines.md).
