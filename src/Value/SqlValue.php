<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * An SQL value PHP has no type of its own for, as the driver reads it from
 * a row and takes it as a parameter (Appendix A of the manual).
 *
 * The rest are PHP's: null, a string for a character string, an int or an
 * {@see Exact} for an exact number, a float for an approximate one, and a
 * bool for a truth value.
 */
interface SqlValue {}
