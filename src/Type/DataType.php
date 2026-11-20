<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A data type, as the protocol writes it: its tag, then what its kind
 * writes after it [PROTOCOL Data types]. Columns, parameters and the types
 * within other types are of these.
 */
interface DataType {}
