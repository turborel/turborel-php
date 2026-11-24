<?php

declare(strict_types=1);

namespace Turborel\Pdo;

use Turborel\Internal\Misuse;
use Turborel\Internal\Pdo\Fetching;
use Turborel\Session\Connection as Client;
use Turborel\Settings\Options;
use Turborel\Settings\Settings;
use Turborel\Turborel;
use Turborel\TurborelException;

/**
 * A connection in PDO's shape, the driver's standard interface (RULES.md
 * D2): made from a `turborel://` URI, as `\PDO` is from a DSN, or from the
 * driver's settings; `prepare` and `query` give statements, `exec` runs one
 * and gives the rows it changed; transactions as PDO's, each statement its
 * own transaction unless one is begun or autocommit is turned off; errors
 * always raised as TurborelException. The low-level client is reached by
 * `client`.
 */
final class Connection
{
    /** The driver's name, as ATTR_DRIVER_NAME gives it. */
    public const string DRIVER = 'turborel';

    /** Whether a transaction was begun by `beginTransaction`. */
    private bool $begun = false;

    /** Whether each statement is its own transaction outside one begun, as ATTR_AUTOCOMMIT says. */
    private bool $autocommit = true;

    /** The fetch mode statements take unless told. */
    private int $fetchMode = Pdo::FETCH_BOTH;

    /** The last identity value an INSERT generated, if one did. */
    private ?string $lastInsertId = null;

    /** The last failure of an operation, for `errorCode` and `errorInfo`. */
    private ?TurborelException $failure = null;

    /** The low-level client this connection runs on. */
    private Client $client;

    /**
     * The connection the connection URI `$dsn` says, or the driver's settings, as `$username` proved by `$password` if given,
     * its attributes `$options`.
     *
     * @param array<int, mixed>|null $options
     *
     * @throws TurborelException SQLSTATE 08001 of a URI or setting that cannot be read; the connection's failure
     */
    public function __construct(string|Settings $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        if ($dsn instanceof Settings) {
            $this->client = $dsn->connect();
        } else {
            $settings = Options::fromUri($dsn);
            if ($username !== null) {
                $settings->set(Options::USER, $username);
            }
            if ($password !== null) {
                $settings->set(Options::PASSWORD, $password);
            }
            $this->client = Settings::resolve($settings)->connect();
        }
        $this->opened($options ?? []);
    }

    /**
     * The connection `$settings` make, its attributes `$attributes`.
     *
     * @param array<int, mixed> $attributes
     *
     * @throws TurborelException the connection's failure
     */
    public static function fromSettings(Settings $settings, array $attributes = []): self
    {
        return new self($settings, null, null, $attributes);
    }

    /** The low-level client this connection runs on, for what the standard interface has no place for. */
    public function client(): Client
    {
        return $this->client;
    }

    /**
     * A statement prepared of `$sql`, its parameters written `?`.
     *
     * @param array<int, mixed> $options none is taken
     *
     * @throws TurborelException the server's, as SQLSTATE 42000 of a statement not preparable
     */
    public function prepare(string $sql, array $options = []): Statement
    {
        return $this->watched(fn(): Statement => Statement::prepared($this, $sql, $this->client->prepare($sql)));
    }

    /**
     * A statement of `$sql` run at once, its rows to be fetched in `$mode`, or the default mode, with `$args` as setFetchMode takes them.
     *
     * @throws TurborelException the statement's failure
     */
    public function query(string $sql, ?int $mode = null, mixed ...$args): Statement
    {
        $statement = Statement::direct($this, $sql);
        $statement->setFetchMode($mode ?? $this->fetchMode, ...$args);
        $statement->execute();

        return $statement;
    }

    /**
     * Runs `$sql`: the rows it inserted, updated, deleted or merged, or a query returned; 0 if none is told.
     *
     * @throws TurborelException the statement's failure
     */
    public function exec(string $sql): int
    {
        return $this->query($sql, Pdo::FETCH_NUM)->rowCount();
    }

    /**
     * Begins a transaction, which lasts until `commit` or `rollBack`.
     *
     * @throws TurborelException SQLSTATE TB910 if one is begun already
     */
    public function beginTransaction(): bool
    {
        if ($this->begun) {
            throw Misuse::of('there is already an active transaction');
        }
        $this->client->setAutocommit(false);
        $this->begun = true;

        return true;
    }

    /**
     * Commits the transaction.
     *
     * @throws TurborelException SQLSTATE TB910 if none is active; the commit's failure, as SQLSTATE 40002
     */
    public function commit(): bool
    {
        return $this->ended('COMMIT');
    }

    /**
     * Rolls the transaction back.
     *
     * @throws TurborelException SQLSTATE TB910 if none is active
     */
    public function rollBack(): bool
    {
        return $this->ended('ROLLBACK');
    }

    /** Whether a transaction is active: one begun, or one the session's last statement left open. */
    public function inTransaction(): bool
    {
        return $this->begun || $this->client->inTransaction();
    }

    /** The last identity value an INSERT generated, as SQL writes it; false if none did. */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastInsertId ?? false;
    }

    /** `$string` as a character string literal, a quote in it written twice. */
    public function quote(string $string, int $type = Pdo::PARAM_STR): string
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }

    /**
     * Sets the attribute `$attribute` to `$value`.
     *
     * @throws TurborelException SQLSTATE TB910 of an attribute not set here, or an error mode other than exceptions
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        match ($attribute) {
            Pdo::ATTR_ERRMODE => $value === Pdo::ERRMODE_EXCEPTION ? null : throw Misuse::of('errors are always raised as exceptions'),
            Pdo::ATTR_AUTOCOMMIT => $this->autocommit(\is_bool($value) ? $value : throw Misuse::of('autocommit is true or false')),
            Pdo::ATTR_DEFAULT_FETCH_MODE => $this->fetchMode = Fetching::mode(\is_int($value) ? $value : -1),
            default => throw Misuse::of("the attribute {$attribute} is not set here"),
        };

        return true;
    }

    /**
     * The value of the attribute `$attribute`.
     *
     * @throws TurborelException SQLSTATE TB910 of an attribute not read here
     */
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            Pdo::ATTR_ERRMODE => Pdo::ERRMODE_EXCEPTION,
            Pdo::ATTR_AUTOCOMMIT => $this->autocommit,
            Pdo::ATTR_DEFAULT_FETCH_MODE => $this->fetchMode,
            Pdo::ATTR_SERVER_VERSION => $this->client->server(),
            Pdo::ATTR_CLIENT_VERSION => Turborel::VERSION,
            Pdo::ATTR_DRIVER_NAME => self::DRIVER,
            default => throw Misuse::of("the attribute {$attribute} is not read here"),
        };
    }

    /** The SQLSTATE of the last operation's failure, `00000` if it did not fail. */
    public function errorCode(): string
    {
        return $this->failure?->getSqlState() ?? '00000';
    }

    /**
     * The last operation's failure as PDO gives it: its SQLSTATE, SQLCODE and message; `00000` and nothing else if it did not fail.
     *
     * @return array{string, ?int, ?string}
     */
    public function errorInfo(): array
    {
        return $this->failure?->errorInfo() ?? ['00000', null, null];
    }

    /**
     * What `$run` gives, its failure kept for `errorInfo` and raised; the last identity value it generated kept.
     *
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     *
     * @throws TurborelException its failure
     *
     * @internal
     */
    public function watched(\Closure $run): mixed
    {
        try {
            $done = $run();
            $this->failure = null;
        } catch (TurborelException $failure) {
            $this->failure = $failure;

            throw $failure;
        }
        $generated = $this->client->completed()?->generated() ?? [];
        if ($generated !== []) {
            $this->lastInsertId = $generated[\count($generated) - 1];
        }

        return $done;
    }

    /** The fetch mode statements take unless told. */
    public function fetchMode(): int
    {
        return $this->fetchMode;
    }

    /**
     * Takes the attributes `$attributes` as the connection opens: each statement its own transaction unless they say otherwise.
     *
     * @param array<int, mixed> $attributes
     *
     * @throws TurborelException SQLSTATE TB910 of an attribute not set here
     */
    private function opened(array $attributes): void
    {
        $this->begun = false;
        $this->fetchMode = Pdo::FETCH_BOTH;
        $this->autocommit(true);
        foreach ($attributes as $attribute => $value) {
            $this->setAttribute($attribute, $value);
        }
    }

    /** Each statement its own transaction if `$on`, outside a transaction begun. */
    private function autocommit(bool $on): void
    {
        $this->autocommit = $on;
        if (!$this->begun) {
            $this->client->setAutocommit($on);
        }
    }

    /**
     * Ends the transaction by `$sql`, COMMIT or ROLLBACK, autocommit as it was after.
     *
     * @throws TurborelException SQLSTATE TB910 if none is active; the statement's failure
     */
    private function ended(string $sql): bool
    {
        if (!$this->inTransaction()) {
            throw Misuse::of('there is no active transaction');
        }
        try {
            $this->watched(fn() => $this->client->query($sql));
        } finally {
            $this->begun = false;
            $this->client->setAutocommit($this->autocommit);
        }

        return true;
    }
}
