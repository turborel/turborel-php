<?php

declare(strict_types=1);

namespace Turborel\Pdo;

use Turborel\Internal\Misuse;
use Turborel\Internal\Pdo\Binding;
use Turborel\Internal\Pdo\ColumnMeta;
use Turborel\Internal\Pdo\Fetching;
use Turborel\Statements\Outcome;
use Turborel\Statements\Statement as Prepared;
use Turborel\TurborelException;

/**
 * A statement in PDO's shape, the driver's standard interface (RULES.md
 * D2): prepared, its `?` parameters bound by position from 1, or run as
 * written; executed, its rows read in a fetch mode, by `fetch`,
 * `fetchAll`, `fetchColumn` or iteration.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Statement implements \IteratorAggregate
{
    /** @var array<int, array{mixed, int}> the values bound, each by its position from 1, with its type */
    private array $bound = [];

    /** @var array<int, array{mixed, int}> the variables bound, each by its position from 1, with its type */
    private array $references = [];

    /** The outcome of its last execution, if it has one. */
    private ?Outcome $outcome = null;

    /** The next row's place. */
    private int $next = 0;

    /** The fetch mode it reads rows in unless told. */
    private int $mode;

    /** The column FETCH_COLUMN reads, or the class FETCH_CLASS makes. */
    private mixed $argument = 0;

    /** @var list<mixed> the arguments of FETCH_CLASS's constructor */
    private array $arguments = [];

    /** The statement of `$connection`, its text `$queryString`, prepared as `$prepared`, or run as written if none. */
    private function __construct(
        private readonly Connection $connection,
        /** Its text, as PDO's `queryString`. */
        public readonly string $queryString,
        private readonly ?Prepared $prepared,
    ) {
        $this->mode = $connection->fetchMode();
    }

    /**
     * The statement `$sql`, prepared as `$prepared`.
     *
     * @internal
     */
    public static function prepared(Connection $connection, string $sql, Prepared $prepared): self
    {
        return new self($connection, $sql, $prepared);
    }

    /**
     * The statement `$sql`, run as written.
     *
     * @internal
     */
    public static function direct(Connection $connection, string $sql): self
    {
        return new self($connection, $sql, null);
    }

    /**
     * Binds `$value` to the parameter of position `$param`, from 1, of the type `$type`.
     *
     * @throws TurborelException SQLSTATE TB910 of a name, as `?` parameters are taken by position alone
     */
    public function bindValue(int|string $param, mixed $value, int $type = Pdo::PARAM_STR): bool
    {
        $this->bound[Binding::position($param)] = [$value, $type];

        return true;
    }

    /**
     * Binds the variable `$var` to the parameter of position `$param`, from 1, its value read as the statement executes.
     *
     * @throws TurborelException SQLSTATE TB910 of a name
     */
    public function bindParam(int|string $param, mixed &$var, int $type = Pdo::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $at = Binding::position($param);
        $this->references[$at] = [&$var, $type];

        return true;
    }

    /**
     * Executes it, with `$params`, a value for each `?` in order, if given, else the values bound: of a CALL whose `?`
     * marks' modes the server told, of its IN and INOUT ones alone, the OUT and INOUT values then written back to the
     * variables bound at their positions.
     *
     * @param list<mixed>|null $params
     *
     * @throws TurborelException the statement's failure; SQLSTATE TB910 of parameters given a statement run as written
     */
    public function execute(?array $params = null): bool
    {
        $marks = $this->prepared?->called?->parameters;
        $values = $params ?? Binding::values($this->bound, $this->references, $marks);
        $this->outcome = $this->connection->watched(function () use ($values): Outcome {
            $client = $this->connection->client();
            if ($this->prepared === null) {
                return $values === [] ? $client->query($this->queryString) : throw Misuse::of('a statement run as written takes no parameters');
            }

            return $client->execute($this->prepared, $values);
        });
        if ($marks !== null && $params === null) {
            Binding::writeBack($this->references, $marks, $this->outcome->rows[0] ?? []);
        }
        $this->next = 0;

        return true;
    }

    /**
     * The next row, in `$mode` or its own, false past the last.
     *
     * @throws TurborelException SQLSTATE TB910 of a mode not taken, or another orientation than the next row
     */
    public function fetch(?int $mode = null, int $cursorOrientation = Pdo::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($cursorOrientation !== Pdo::FETCH_ORI_NEXT) {
            throw Misuse::of('a statement is read from its next row alone');
        }
        $row = $this->outcome->rows[$this->next] ?? null;
        if ($row === null) {
            return false;
        }
        ++$this->next;

        return Fetching::row($row, $this->outcome->columns ?? [], Fetching::mode($mode ?? $this->mode), $this->argument, $this->arguments);
    }

    /**
     * The rows left, in `$mode` or its own, with `$args` as setFetchMode takes them.
     *
     * @return list<mixed>
     *
     * @throws TurborelException SQLSTATE TB910 of a mode not taken
     */
    public function fetchAll(?int $mode = null, mixed ...$args): array
    {
        if ($mode !== null) {
            $this->setFetchMode($mode, ...$args);
        }
        $rows = [];
        while (($row = $this->fetch()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The column `$column`, from 0, of the next row, false past the last.
     *
     * @throws TurborelException SQLSTATE TB910 of a column not there
     */
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(Pdo::FETCH_NUM);
        if (!\is_array($row)) {
            return false;
        }

        return \array_key_exists($column, $row) ? $row[$column] : throw Misuse::of("the column {$column} is not there");
    }

    /**
     * Sets the mode it reads rows in: of FETCH_COLUMN its column, from 0; of FETCH_CLASS its class and the constructor's arguments.
     *
     * @throws TurborelException SQLSTATE TB910 of a mode not taken
     */
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->mode = Fetching::mode($mode);
        $this->argument = $args[0] ?? 0;
        $this->arguments = array_values(\is_array($args[1] ?? null) ? $args[1] : []);

        return true;
    }

    /** The rows its last execution inserted, updated, deleted or merged, or returned; 0 if it told none. */
    public function rowCount(): int
    {
        $rows = $this->outcome?->completed?->rows();
        if ($rows === null) {
            return \count($this->outcome->rows ?? []);
        }

        return (int) $rows;
    }

    /** The columns of its rows: of a query, one for each; none of another statement. */
    public function columnCount(): int
    {
        return \count($this->outcome->columns ?? $this->prepared->columns ?? []);
    }

    /**
     * What PDO's getColumnMeta gives of the column `$column`, from 0: its name, its SQL type's name, as declared where
     * `declared-types` was granted, its whole type as SQL writes it as `turborel:decl_type`, the PDO type it reads as, its
     * length and precision, and whether it takes no null; false if there is no such column.
     *
     * @return array{name: string, native_type: string, 'turborel:decl_type': string, pdo_type: int, len: int, precision: int, flags: list<string>}|false
     */
    public function getColumnMeta(int $column): array|false
    {
        $columns = $this->outcome->columns ?? $this->prepared->columns ?? [];

        return isset($columns[$column]) ? ColumnMeta::of($columns[$column]) : false;
    }

    /** Lets its rows go, so it may be executed again. */
    public function closeCursor(): bool
    {
        [$this->outcome, $this->next] = [null, 0];

        return true;
    }

    /** The SQLSTATE of its connection's last operation's failure, `00000` if it did not fail. */
    public function errorCode(): string
    {
        return $this->connection->errorCode();
    }

    /**
     * Its connection's last failure as PDO gives it.
     *
     * @return array{string, ?int, ?string}
     */
    public function errorInfo(): array
    {
        return $this->connection->errorInfo();
    }

    /**
     * Its rows left, each in its fetch mode.
     *
     * @return \Generator<int, mixed>
     *
     * @throws TurborelException SQLSTATE TB910 of a mode not taken
     */
    public function getIterator(): \Generator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }
}
