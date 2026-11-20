<?php

declare(strict_types=1);

namespace Turborel\Pool;

use Turborel\Internal\Pool\Idle;
use Turborel\Internal\Pool\Made;
use Turborel\Internal\Pool\Resetting;
use Turborel\Internal\Pool\Shared;
use Turborel\Internal\Settings\Invalid;
use Turborel\Session\Connection;
use Turborel\Settings\Settings;
use Turborel\TurborelException;

/**
 * A pool of connections, as turborel-client's `Pool` [CLIENT Pools]: a
 * bounded set a program shares, its clones the same pool. `get` hands out
 * an idle connection that answers, or makes one while fewer than `max`
 * are made, or waits for one to come back, at most `wait`; a connection
 * handed out comes back reset to the session it logged in with as it is
 * released. The pool starts no thread: each wait is its waiter's, which
 * within a Fiber suspends it, so another may give one back.
 */
final class Pool
{
    /** What the pool's clones share. */
    private Shared $shared;

    /**
     * A pool of the connections `$source` makes: Settings, a Source, or a closure that makes one; kept as `$config` says.
     *
     * @param Settings|Source|(\Closure(): Connection) $source
     */
    public function __construct(Settings|Source|\Closure $source, ?PoolConfig $config = null)
    {
        $this->shared = new Shared($source instanceof \Closure || $source instanceof Settings ? new Made($source) : $source, $config ?? new PoolConfig());
    }

    /**
     * A connection: an idle one that answers, else a new one while the pool has room, else the first to come back within `wait`.
     *
     * @throws TurborelException SQLSTATE 08001 if none comes back in time; the source's failure to make one
     */
    public function get(): Pooled
    {
        $config = $this->shared->config;
        $deadline = (int) hrtime(true) + (int) ($config->wait * 1e9);
        while (true) {
            $idle = $this->shared->take();
            if ($idle instanceof Idle) {
                if (!$config->check || Resetting::answers($idle->connection)) {
                    return new Pooled($idle->connection, $idle->made, $this->shared);
                }
                $this->shared->unmade($idle->connection);

                continue;
            }
            if ($this->shared->room()) {
                return $this->make();
            }
            if ((int) hrtime(true) >= $deadline) {
                throw Invalid::because("no connection of the pool's {$config->max} came back within " . self::shown($config->wait));
            }
            $this->shared->await();
        }
    }

    /**
     * How many connections are made, handed out and idle, and how many of them are idle.
     *
     * @return array{int, int}
     */
    public function counts(): array
    {
        return [$this->shared->made, \count($this->shared->idle)];
    }

    /**
     * A new connection, in the place taken for it.
     *
     * @throws TurborelException the source's failure
     */
    private function make(): Pooled
    {
        try {
            return new Pooled($this->shared->source->connect(), (int) hrtime(true), $this->shared);
        } catch (TurborelException $failure) {
            $this->shared->unmade(null);

            throw $failure;
        }
    }

    /** `$seconds` as Rust's Debug writes a Duration: `30s`, `1.5s`, `500ms`, `20µs` or `7ns`, no trailing zero. */
    private static function shown(float $seconds): string
    {
        $nanos = (int) round($seconds * 1e9);
        foreach ([[1_000_000_000, 's'], [1_000_000, 'ms'], [1_000, 'µs']] as [$unit, $suffix]) {
            if ($nanos >= $unit) {
                $fraction = rtrim(str_pad((string) ($nanos % $unit), \strlen((string) $unit) - 1, '0', \STR_PAD_LEFT), '0');

                return intdiv($nanos, $unit) . ($fraction === '' ? '' : ".{$fraction}") . $suffix;
            }
        }

        return "{$nanos}ns";
    }
}
