<?php

declare(strict_types=1);

namespace Turborel\Settings;

use Turborel\Internal\Settings\Invalid;
use Turborel\Internal\Settings\Uri;
use Turborel\TurborelException;

/**
 * A layer of connection settings, each by its name, as turborel-client's
 * `Options` [CLIENT Where settings come from]: as a program, a URI, a
 * service's section or the environment gives them. The layers are laid
 * one over another, each setting taken from the first that has it.
 */
final class Options
{
    /** The setting `host`, as the program, a URI's query and a service file write it. */
    public const string HOST = 'host';

    /** The setting `port`, as the program, a URI's query and a service file write it. */
    public const string PORT = 'port';

    /** The setting `user`, as the program, a URI's query and a service file write it. */
    public const string USER = 'user';

    /** The setting `password`, as the program, a URI's query and a service file write it. */
    public const string PASSWORD = 'password';

    /** The setting `tls_mode`, as the program, a URI's query and a service file write it. */
    public const string TLS_MODE = 'tls_mode';

    /** The setting `channel_binding`, as the program, a URI's query and a service file write it. */
    public const string CHANNEL_BINDING = 'channel_binding';

    /** The setting `application_name`, as the program, a URI's query and a service file write it. */
    public const string APPLICATION_NAME = 'application_name';

    /** The setting `connect_timeout`, as the program, a URI's query and a service file write it. */
    public const string CONNECT_TIMEOUT = 'connect_timeout';

    /** The setting `service`, as the program, a URI's query and a service file write it. */
    public const string SERVICE = 'service';

    /** The setting `service_file`, as the program, a URI's query and a service file write it. */
    public const string SERVICE_FILE = 'service_file';

    /** The setting `passfile`, as the program, a URI's query and a service file write it. */
    public const string PASSFILE = 'passfile';

    /** The setting `target`, as the program, a URI's query and a service file write it. */
    public const string TARGET = 'target';

    /** The setting `load_balance`, as the program, a URI's query and a service file write it. */
    public const string LOAD_BALANCE = 'load_balance';

    /** The setting `tls_roots`, as the program, a URI's query and a service file write it. */
    public const string TLS_ROOTS = 'tls_roots';

    /** The setting `tls_system_roots`, as the program, a URI's query and a service file write it. */
    public const string TLS_SYSTEM_ROOTS = 'tls_system_roots';

    /** The setting `tls_certificate`, as the program, a URI's query and a service file write it. */
    public const string TLS_CERTIFICATE = 'tls_certificate';

    /** The setting `tls_key`, as the program, a URI's query and a service file write it. */
    public const string TLS_KEY = 'tls_key';

    /** The setting `server_name`, as the program, a URI's query and a service file write it. */
    public const string SERVER_NAME = 'server_name';

    /** Every setting's name. */
    public const array KEYS = [self::HOST, self::PORT, self::USER, self::PASSWORD, self::TLS_MODE, self::CHANNEL_BINDING, self::APPLICATION_NAME,
        self::CONNECT_TIMEOUT, self::SERVICE, self::SERVICE_FILE, self::PASSFILE, self::TARGET, self::LOAD_BALANCE, self::TLS_ROOTS,
        self::TLS_SYSTEM_ROOTS, self::TLS_CERTIFICATE, self::TLS_KEY, self::SERVER_NAME];

    /** The environment's variables, each the setting it gives. */
    public const array ENVIRONMENT = ['TURBOREL_HOST' => self::HOST, 'TURBOREL_PORT' => self::PORT, 'TURBOREL_USER' => self::USER,
        'TURBOREL_PASSWORD' => self::PASSWORD, 'TURBOREL_TLS_MODE' => self::TLS_MODE, 'TURBOREL_APPLICATION_NAME' => self::APPLICATION_NAME,
        'TURBOREL_CONNECT_TIMEOUT' => self::CONNECT_TIMEOUT, 'TURBOREL_SERVICE' => self::SERVICE, 'TURBOREL_SERVICE_FILE' => self::SERVICE_FILE,
        'TURBOREL_PASSFILE' => self::PASSFILE];

    /** @var array<string, string> the settings, each by its name */
    private array $values = [];

    /**
     * Sets `$key` to `$value`.
     *
     * @throws TurborelException SQLSTATE 08001 if `$key` names no setting
     */
    public function set(string $key, #[\SensitiveParameter] string $value): self
    {
        if (!\in_array($key, self::KEYS, true)) {
            throw Invalid::because("{$key} is no connection setting");
        }
        $this->values[$key] = $value;

        return $this;
    }

    /** The value of `$key`, if set. */
    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /** Whether none is set. */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** These settings laid over `$lower`: each of `$lower`'s kept where these have none. */
    public function over(self $lower): self
    {
        $laid = new self();
        $laid->values = $this->values + $lower->values;

        return $laid;
    }

    /**
     * The settings the environment gives, `$variable` reading each variable of ENVIRONMENT: an empty one gives none.
     *
     * @param \Closure(string): (string|null|false) $variable
     */
    public static function environment(\Closure $variable): self
    {
        $options = new self();
        foreach (self::ENVIRONMENT as $name => $key) {
            $value = $variable($name);
            if (\is_string($value) && $value !== '') {
                $options->values[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * The settings the connection URI `$uri` gives [CLIENT The connection URI].
     *
     * @throws TurborelException SQLSTATE 08001 if it is not one: another scheme, a path, a key of no setting, a pair without `=`, or bad percent-encoding
     */
    public static function fromUri(#[\SensitiveParameter] string $uri): self
    {
        return Uri::options($uri);
    }

    /**
     * Its settings as var_dump() shows them: never the password.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        $shown = $this->values;
        if (isset($shown[self::PASSWORD])) {
            $shown[self::PASSWORD] = '...';
        }

        return $shown;
    }
}
