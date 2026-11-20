<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The class a file holds, found from its path as PSR-4 maps it.
 */
final class ClassFile
{
    /** A PHP file's suffix. */
    private const string SUFFIX = '.php';

    /**
     * The class, interface, trait or enum `$file` holds, its path below
     * `$directory` (as `/src/`) mapped onto `$namespace`; or none if the
     * file declares none of that name.
     *
     * @return class-string|null
     */
    public static function name(string $file, string $directory, string $namespace): ?string
    {
        $at = strrpos($file, $directory);
        if ($at === false) {
            return null;
        }
        $relative = substr($file, $at + \strlen($directory), -\strlen(self::SUFFIX));
        $name = $namespace . str_replace('/', '\\', $relative);
        $exists = class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);

        return $exists ? $name : null;
    }
}
