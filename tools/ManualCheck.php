<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The manual's layout, which turborel.com's documentation site reads
 * (RULES.md R-DOC-7): each page opens with its `# Title` and a line
 * holding only `[Contents](README.md)`, each volume's README.md lists its
 * pages, `user/01-installing.md` keeps its name, and the manual holds no
 * image. Its relative links resolve, as LinkCheck checks of every
 * document; the site points those that leave `doc/` at GitHub.
 */
final class ManualCheck
{
    /** The manual's directory, from the root. */
    private const string MANUAL = 'doc';

    /** A volume's contents page, and the set's. */
    private const string CONTENTS = 'README.md';

    /** The line after a page's title. */
    private const string CONTENTS_LINE = '[Contents](README.md)';

    /** A page's title. */
    private const string TITLE = '# ';

    /** The line, counting from 0, that holds a page's contents link: after its title and a blank line. */
    private const int CONTENTS_AT = 2;

    /** The page of installing, which the site links to by its name. */
    private const string INSTALLING = 'user/01-installing.md';

    /** A page's extension. */
    private const string PAGE = 'md';

    /** An image in Markdown, or in HTML. */
    private const string IMAGE = '/!\[|<img\b/i';

    /**
     * What breaks R-DOC-7's layout in the manual under `$root`.
     *
     * @return list<string>
     */
    public static function check(string $root): array
    {
        $manual = $root . '/' . self::MANUAL;
        $findings = [];
        foreach (self::glob($manual . '/*', \GLOB_ONLYDIR) as $volume) {
            $findings = [...$findings, ...self::volume($volume)];
        }
        if (!is_file($manual . '/' . self::INSTALLING)) {
            $findings[] = "{$manual}/" . self::INSTALLING . ': the page of installing keeps its name (R-DOC-7)';
        }

        return [...$findings, ...self::images($manual)];
    }

    /**
     * The images, and other files than pages, in the manual at `$manual`.
     *
     * @return list<string>
     */
    private static function images(string $manual): array
    {
        $findings = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($manual, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() !== self::PAGE) {
                $findings[] = "{$file->getPathname()}: the manual holds pages alone, no image or other file (R-DOC-7)";
            }
        }
        foreach (LinkCheck::documents($manual) as $page) {
            if (preg_match(self::IMAGE, LinkCheck::prose($page)) === 1) {
                $findings[] = "{$page}: the manual holds no image (R-DOC-7)";
            }
        }

        return $findings;
    }

    /**
     * The paths `$pattern` matches, of glob()'s `$flags`; none if it fails.
     *
     * @return list<string>
     */
    private static function glob(string $pattern, int $flags): array
    {
        $paths = glob($pattern, $flags);

        return $paths === false ? [] : $paths;
    }

    /**
     * What is wrong with the pages of the volume `$volume`, and its contents page.
     *
     * @return list<string>
     */
    private static function volume(string $volume): array
    {
        $findings = [];
        $contents = Sources::read($volume . '/' . self::CONTENTS);
        foreach (self::glob($volume . '/*.md', 0) as $page) {
            $name = basename($page);
            if ($name === self::CONTENTS) {
                continue;
            }
            $lines = explode("\n", Sources::read($page));
            if (!str_starts_with($lines[0], self::TITLE) || ($lines[self::CONTENTS_AT] ?? '') !== self::CONTENTS_LINE) {
                $findings[] = "{$page}: a page opens with its `# Title`, then a line `[Contents](README.md)` (R-DOC-7)";
            }
            if (!str_contains($contents, "]({$name})")) {
                $findings[] = "{$volume}/README.md: does not list {$name} (R-DOC-7)";
            }
        }

        return $findings;
    }
}
