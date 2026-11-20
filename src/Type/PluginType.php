<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A plug-in's type, tag 4: its name and its parameters [PROTOCOL Data types].
 * The driver has no plug-in's types; it keeps the name, and shows the
 * values by their text.
 */
final readonly class PluginType implements DataType
{
    /**
     * The plug-in type `$name` of the parameters `$parameters`.
     *
     * @param list<int> $parameters
     */
    public function __construct(
        /** Its name. */
        public string $name,
        /** @var list<int> $parameters its parameters, each a u32 */
        public array $parameters = [],
    ) {}
}
