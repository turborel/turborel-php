<?php

declare(strict_types=1);

namespace Turborel\Pipeline;

/**
 * What a pipeline does after a request fails, as turborel-client's
 * `OnError` [CLIENT Pipelines].
 */
enum OnError
{
    /** Every request runs; a failure is its request's outcome alone. */
    case Continue;

    /** The batch stops at its first failure: the requests after it undone or never sent, each skipped. */
    case Skip;
}
