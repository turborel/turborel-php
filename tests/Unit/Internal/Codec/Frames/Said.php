<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec\Frames;

use Turborel\Debug\Breakpoint;
use Turborel\Debug\Cursor;
use Turborel\Debug\Frame;
use Turborel\Debug\Named;
use Turborel\Debug\Paused;
use Turborel\Internal\Codec\Completion;
use Turborel\Internal\Codec\Failure;
use Turborel\Internal\Codec\Reply\Challenge;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Debugged;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\EventSent;
use Turborel\Internal\Codec\Reply\LobCreated;
use Turborel\Internal\Codec\Reply\LobData;
use Turborel\Internal\Codec\Reply\Notice;
use Turborel\Internal\Codec\Reply\Prepared;
use Turborel\Internal\Codec\Reply\Ready;
use Turborel\Internal\Codec\Reply\Reply;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\Codec\Reply\Status;
use Turborel\Internal\Codec\Written;
use Turborel\Internal\Copy\Text;
use Turborel\TurborelException;
use Turborel\Type\Column;
use Turborel\Type\Parameter;

/**
 * A reply decoded, said in words, as a sample frame's meaning says it,
 * for the sample frames' test to compare.
 */
final class Said
{
    /**
     * What `$reply` says.
     *
     * @throws TurborelException never, every value having a literal
     */
    public static function of(Reply $reply): string
    {
        return match (true) {
            $reply instanceof Challenge => "Challenge {$reply->challenge}",
            $reply instanceof Ready => "Ready {$reply->authorization}, key " . strtoupper(bin2hex($reply->key)) . ', ' . implode(' ', $reply->capabilities) . ", {$reply->server}",
            $reply instanceof Prepared => "Prepared {$reply->statement}, parameters " . implode(', ', array_map(self::parameter(...), $reply->parameters)) . '; ' . self::columns($reply->columns)
                . ($reply->called === null ? '' : "; called {$reply->called->schema}.{$reply->called->name}: " . implode(', ', array_map(self::mode(...), $reply->called->parameters))),
            $reply instanceof Description => 'Description ' . self::columns($reply->columns),
            $reply instanceof Rows => trim('Rows ' . implode(' ', array_map(static fn(array $row): string => '(' . implode(', ', array_map(Text::literal(...), $row)) . ')', $reply->rows))),
            $reply instanceof Complete => 'Complete ' . self::completion($reply->completion),
            $reply instanceof Error => 'Error ' . self::failure($reply->failure),
            $reply instanceof LobData => 'Lob data ' . strtoupper(bin2hex($reply->data)),
            $reply instanceof LobCreated => "Lob locator {$reply->locator}",
            $reply instanceof Notice => "Notice {$reply->sqlstate} {$reply->message}" . self::fields($reply->diagnostics->fields ?? []),
            $reply instanceof Status => "Status {$reply->name}={$reply->value}",
            $reply instanceof Debugged => 'Debugged ' . self::debugged($reply->value),
            $reply instanceof EventSent => 'Event ' . strtolower($reply->event->kind->name) . ($reply->event->paused === null ? '' : ' ' . self::paused($reply->event->paused)),
            default => (new \ReflectionClass($reply))->getShortName(),
        };
    }

    /**
     * What a Debugged gives: a connection's ID, a breakpoint, the breakpoints, or a pause, none of a target running.
     *
     * @param int|string|Breakpoint|list<Breakpoint>|Paused|null $value
     *
     * @throws TurborelException never, every value having a literal
     */
    private static function debugged(int|string|Breakpoint|array|Paused|null $value): string
    {
        $breakpoint = static fn(Breakpoint $b): string => "#{$b->number} {$b->schema}.{$b->name} line {$b->line}" . ($b->condition === null ? '' : " if {$b->condition}");

        return match (true) {
            \is_int($value), \is_string($value) => "connection {$value}",
            $value instanceof Breakpoint => 'breakpoint ' . $breakpoint($value),
            \is_array($value) => 'breakpoints ' . implode('; ', array_map($breakpoint, $value)),
            $value instanceof Paused => 'stack ' . self::paused($value),
            default => 'stack running',
        };
    }

    /**
     * A pause: its reason, then each frame, its origin, place, parameters, variables and cursors.
     *
     * @throws TurborelException never, every value having a literal
     */
    private static function paused(Paused $paused): string
    {
        $frame = static fn(Frame $f): string => ($f->origin === null ? 'direct' : strtolower($f->origin->kind->name) . " {$f->origin->schema}.{$f->origin->specificName} ({$f->origin->shownSchema}.{$f->origin->name})")
            . ($f->visible ? '' : ' hidden') . ' at ' . ($f->place->at ?? '-') . ' line ' . ($f->place->line ?? '-') . ':' . ($f->place->column ?? '-')
            . ' [' . self::named($f->scope->parameters) . '] [' . self::named($f->scope->variables) . '] ['
            . implode(', ', array_map(static fn(Cursor $c): string => "{$c->name} " . ($c->open ? 'open' : 'closed') . " {$c->query}", $f->scope->cursors)) . ']';

        return ($paused->breakpoint === null ? 'step' : "breakpoint {$paused->breakpoint}") . ': ' . implode(' / ', array_map($frame, $paused->frames));
    }

    /**
     * Parameters or variables, each its name, type and value.
     *
     * @param list<Named> $all
     *
     * @throws TurborelException never, every value having a literal
     */
    private static function named(array $all): string
    {
        return implode(', ', array_map(static fn(Named $n): string => "{$n->name} {$n->type} " . Text::literal($n->value), $all));
    }

    /** A `?` of a CALL: its mode, its parameter's ordinal position, and its type as written. */
    private static function mode(Parameter $parameter): string
    {
        return strtoupper($parameter->mode->name) . " {$parameter->ordinal} " . self::parameter($parameter);
    }

    /** A parameter: its type as written, `varying` where it is flagged so. */
    private static function parameter(Parameter $parameter): string
    {
        return Written::of($parameter->type) . ($parameter->varying ? ' varying' : '');
    }

    /**
     * Columns, each its name, its type as written, NOT NULL, and its declared type where told.
     *
     * @param list<Column> $columns
     */
    private static function columns(array $columns): string
    {
        $one = static fn(Column $c): string => ($c->name ?? '?') . ' ' . Written::of($c->type) . ($c->notNull ? ' NOT NULL' : '')
            . ($c->declared === null ? '' : " declared {$c->declared->name}/{$c->declared->written}");

        return 'columns ' . implode(', ', array_map($one, $columns));
    }

    /** A completion: its codes, active, and each tail field it carries. */
    private static function completion(Completion $c): string
    {
        $tail = array_filter([
            $c->counts->position === null ? null : "position {$c->counts->position}",
            $c->counts->more ? 'more' : null,
            $c->counts->rows === null ? null : "rows {$c->counts->rows}",
            $c->raised[0] === [] ? null : 'generated ' . implode(' ', $c->raised[0]),
            $c->raised[1] === [] ? null : 'conditions ' . implode('; ', array_map(self::failure(...), $c->raised[1])),
            $c->counts->time === null ? null : "time {$c->counts->time->total} {$c->counts->time->queued} {$c->counts->time->locks}",
        ]);

        return "{$c->sqlcode} {$c->sqlstate}" . ($c->active ? ' active' : '') . ($tail === [] ? '' : ', ' . implode(', ', $tail));
    }

    /** A failure: its codes, message, citation, place, active, and diagnostics. */
    private static function failure(Failure $f): string
    {
        $cited = $f->cited->rule === null ? '' : " [{$f->cited->rule}]";
        $placed = $f->cited->position === null ? '' : " at {$f->cited->position}";

        return "{$f->sqlcode} {$f->sqlstate} {$f->message}{$cited}{$placed}" . ($f->state->active ? ' active' : '') . self::fields($f->state->diagnostics->fields ?? []);
    }

    /**
     * Diagnostics fields, each its name and value.
     *
     * @param array<string, string> $fields
     */
    private static function fields(array $fields): string
    {
        return $fields === [] ? '' : ' {' . implode(', ', array_map(static fn(string $k, string $v): string => "{$k}={$v}", array_keys($fields), $fields)) . '}';
    }
}
