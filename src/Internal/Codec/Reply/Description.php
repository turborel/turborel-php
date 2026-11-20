<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

use Turborel\Type\Column;

/**
 * Description 'D': the columns of the rows that follow a Query, or of an open cursor [PROTOCOL Description 'D'].
 *
 * @internal
 */
final readonly class Description implements Reply
{
    /**
     * The reply of its fields.
     *
     * @param list<Column> $columns
     */
    public function __construct(
        /** The columns. */
        public array $columns,
    ) {}
}
