# Settings

[Contents](README.md)

## NAME

`Turborel\Settings`: connection settings from a URI, a service file, the
environment and a password file, and connecting as they say.

## SYNOPSIS

```php
final class Options
{
    public function set(string $key, string $value): self;
    public function get(string $key): ?string;
    public function isEmpty(): bool;
    public function over(Options $lower): self;
    public static function environment(\Closure $variable): self;
    public static function fromUri(string $uri): self;
}

final readonly class Settings
{
    public array $hosts;
    public string $user;
    public TlsMode $tlsMode;
    public ChannelBinding $channelBinding;
    public ?string $applicationName;
    public ?float $connectTimeout;
    public ?string $passfile;
    public TargetRole $target;
    public LoadBalance $loadBalance;
    public TlsFiles $tls;
    public KeyCache $keyCache;
    public static function resolve(Options $explicit): self;
    public static function resolveIn(Options $explicit, \Closure $variable): self;
    public static function fromUri(string $uri): self;
    public static function service(string $file, string $name): Options;
    public function passwordFor(Host $host): ?string;
    public function connect(): Connection;
    public function timeouts(): Timeouts;
}

enum Role { case ReadWrite; case ReadOnly; case Unknown; }
```

`Host` is `Host::tcp($name, $port)` or `Host::local($path)`. `TlsMode`,
`TargetRole` and `LoadBalance` are enums of the settings' words, and
`Session\ChannelBinding` is `channel_binding`'s. `TlsFiles` holds
`roots`, `systemRoots`, `certificate`, `key` and `serverName`.

## DESCRIPTION

`Options` is a layer of settings, each named by one of `Options::KEYS`,
as Appendix C lists them; `set` refuses another name. `over` lays one
layer over another. `environment` reads the variables of
`Options::ENVIRONMENT` through a function, an empty one giving nothing.
`fromUri` reads a connection URI.

`resolve` lays the program's settings over the service its settings or
the environment name, over the environment, over the defaults, and
reads them; `resolveIn` reads the environment through the function
given. `passwordFor` gives the password of a host: the settings', else
the password file's. Neither `Options` nor `Settings` shows a password
to `var_dump`.

`connect` tries each host in order, or at random, and each address its
name resolves to, as `tls_mode` says, each step within the connect
timeout, keeping the first server that can be reached, logs the user in,
and is of the role `target` asks; the password is proved by
SCRAM-SHA-256, bound as `channel_binding` says, with the settings' key
cache, else the certificate named proves the user, else trust.
`Role::of` gives the role a connection's session sees.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08001 | -919 | A setting that cannot be read, a URI that is not one, a service file unread or without the section, no user named; of several servers tried, none kept; a server not of the role asked |
| 28000 | -919 | A password over plain TCP; channel binding required and impossible; the last server tried refusing the user |
| 08006 | -919 | The one server tried not reached, or its TLS failing; a verifying mode with no authority trusted; a certificate named without its key |

## EXAMPLES

```php
use Turborel\Settings\Options;
use Turborel\Settings\Settings;

$settings = Settings::resolveIn(Options::fromUri('turborel://ann@127.0.0.1:7422/?tls_mode=disable&target=read-write'), static fn(string $name): ?string => null);
$connection = $settings->connect();
echo Turborel\Settings\Role::of($connection)->describe(), PHP_EOL;
$connection->terminate();
```

```text
read-write
```

## STANDARD

[CLIENT Connecting by settings], [CLIENT Where settings come from],
[CLIENT The connection URI], [CLIENT The service file],
[CLIENT The password file], [CLIENT Several servers],
[CLIENT TLS by mode], [CLIENT The SCRAM key cache].

## SEE ALSO

[Connections](21-connections.md), [Appendix C](../appendix/c-settings.md).
