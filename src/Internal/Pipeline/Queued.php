<?php

declare(strict_types=1);

namespace Turborel\Internal\Pipeline;

use Turborel\Internal\Codec\Request\Close;
use Turborel\Internal\Codec\Request\Deallocate;
use Turborel\Internal\Codec\Request\Execute;
use Turborel\Internal\Codec\Request\Extras;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Internal\Codec\Request\Request;
use Turborel\Statements\Statement;

/**
 * A request a pipeline queued, and how its reply is read.
 *
 * @internal
 */
final readonly class Queued
{
    /** A Query. */
    public const int QUERY = 0;

    /** An Execute. */
    public const int EXECUTE = 1;

    /** A Close of a statement's cursor 0, of a pool's reset. */
    public const int CLOSE = 2;

    /** A Deallocate, of a pool's reset. */
    public const int DEALLOCATE = 3;

    /** The savepoint OnError::Skip sets before a request, its outcome never shown. */
    public const int SAVEPOINT = 4;

    /** What OnError::Skip's savepoints are named by, then each its place. */
    private const string NAME = 'TURBOREL_PIPELINE';

    /**
     * The request of its parts.
     *
     * @param list<mixed> $values
     */
    private function __construct(
        /** Which request it is. */
        public int $kind,
        /** A Query's text. */
        public string $sql = '',
        /** An Execute's statement. */
        public ?Statement $statement = null,
        /** @var list<mixed> $values an Execute's values */
        public array $values = [],
        /** A Close's or Deallocate's statement number, or a savepoint's place. */
        public int $number = 0,
    ) {}

    /** A Query of `$sql`. */
    public static function query(string $sql): self
    {
        return new self(self::QUERY, $sql);
    }

    /**
     * An Execute of `$statement` with `$values`.
     *
     * @param list<mixed> $values
     */
    public static function execute(Statement $statement, array $values): self
    {
        return new self(self::EXECUTE, '', $statement, $values);
    }

    /** A Close of statement `$number`'s cursor. */
    public static function close(int $number): self
    {
        return new self(self::CLOSE, '', null, [], $number);
    }

    /** A Deallocate of statement `$number`. */
    public static function deallocate(int $number): self
    {
        return new self(self::DEALLOCATE, '', null, [], $number);
    }

    /** The savepoint set before the request of place `$n`. */
    public static function savepoint(int $n): self
    {
        return new self(self::SAVEPOINT, '', null, [], $n);
    }

    /** The savepoint's name of place `$n`. */
    public static function name(int $n): string
    {
        return self::NAME . "_{$n}";
    }

    /**
     * The request it sends, the transaction ended with it if `$ends`, a statement of the program's carrying `$attributes`.
     *
     * @param list<array{string, string}> $attributes
     */
    public function request(bool $ends, array $attributes): Request
    {
        return match ($this->kind) {
            self::QUERY => new Query($this->sql, $ends, $attributes),
            self::EXECUTE => new Execute($this->statement->number ?? 0, $this->values, 0, $ends, new Extras($attributes)),
            self::CLOSE => new Close($this->number),
            self::DEALLOCATE => new Deallocate($this->number),
            default => new Query('SAVEPOINT ' . self::name($this->number)),
        };
    }
}
