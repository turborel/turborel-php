<?php

declare(strict_types=1);

namespace Turborel\Internal\Session;

use Turborel\Internal\Codec\Completion;
use Turborel\Session\Capability;
use Turborel\Session\Notice;

/**
 * What the server has told a connection beside each statement's replies,
 * as turborel-client's `told.rs` keeps it: the capabilities granted, the
 * session's settings as Status gave them, the notices it sent, handed to a
 * handler as they come or the last 64 kept, the log position of the last
 * commit, and the last statement's completion [CLIENT What the server tells].
 *
 * @internal
 */
final class Told
{
    /** The notices kept where no handler takes them, as turborel-client's KEPT_NOTICES. */
    public const int KEPT_NOTICES = 64;

    /** @var list<string> the capabilities granted, as Ready gave them */
    public array $granted = [];

    /** @var array<string, string> the session's settings, as the server last told them */
    public array $settings = [];

    /** @var list<Notice> the notices kept, oldest first */
    public array $notices = [];

    /** @var (\Closure(Notice): void)|null what each notice is handed to, if anything */
    public ?\Closure $handler = null;

    /** The log position of the last commit, with `commit-position`. */
    public int|string|null $position = null;

    /** The last statement's completion. */
    public ?Completion $completed = null;

    /** @var list<array{string, string}> the attributes each Query and Execute carries, with `query-attributes` */
    public array $attributes = [];

    /** Whether the server granted `$capability`. */
    public function has(string $capability): bool
    {
        return \in_array($capability, $this->granted, true);
    }

    /**
     * The attributes a Query or an Execute carries: none unless `query-attributes` was granted.
     *
     * @return list<array{string, string}>
     */
    public function attributes(): array
    {
        return $this->has(Capability::QUERY_ATTRIBUTES) ? $this->attributes : [];
    }

    /** Takes `$notice`: handed to the handler, or kept, the oldest dropped past KEPT_NOTICES. */
    public function notice(Notice $notice): void
    {
        if ($this->handler !== null) {
            ($this->handler)($notice);

            return;
        }
        if (\count($this->notices) >= self::KEPT_NOTICES) {
            array_shift($this->notices);
        }
        $this->notices[] = $notice;
    }
}
