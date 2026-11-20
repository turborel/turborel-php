<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The PHP files the checks read, in a fixed order so their findings come
 * out the same on every run and platform.
 */
final class Sources
{
    /** A PHP file's extension. */
    private const string EXTENSION = 'php';

    /**
     * The PHP files under each of `$directories` of `$root`, sorted, with
     * `/` between path segments on every platform.
     *
     * @param list<string> $directories
     *
     * @return list<string>
     */
    public static function under(string $root, array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === self::EXTENSION) {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }
        sort($files);

        return $files;
    }

    /** The text of `$path`, or none if it cannot be read. */
    public static function read(string $path): string
    {
        $text = is_file($path) ? file_get_contents($path) : false;

        return $text === false ? '' : $text;
    }
}
