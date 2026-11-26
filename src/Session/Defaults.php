<?php

declare(strict_types=1);

namespace Turborel\Session;

/**
 * What a connection asks for unless told otherwise: every capability but
 * compression, in the spec's order, as turborel-client's `Offer` asks
 * [CLIENT What a connection asks for].
 */
final class Defaults
{
    /** Every capability but compression. */
    public const array CAPABILITIES = [
        Capability::NOTICES, Capability::STATUS, Capability::DIAGNOSTICS, Capability::DESCRIBE, Capability::CURSORS,
        Capability::PING, Capability::RESET, Capability::CHANGE_USER, Capability::QUERY_ATTRIBUTES,
        Capability::CONNECTION_ATTRIBUTES, Capability::MULTI_STATEMENT, Capability::COMMIT_POSITION, Capability::COPY,
        Capability::ROW_COUNTS, Capability::GENERATED_VALUES, Capability::FETCH_ENDS, Capability::CONDITIONS,
        Capability::DECLARED_TYPES, Capability::PARAMETER_MODES, Capability::DEBUGGER, Capability::COLUMNS,
    ];
}
