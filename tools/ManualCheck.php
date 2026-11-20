<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The manual's layout, which turborel.com's documentation site reads
 * (RULES.md R-DOC-7): each page opens with its `# Title` and a line
 * holding only `[Contents](README.md)`, each volume's README.md lists its
 * pages, and a link of the manual stays within `doc/` or is a full URL.
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

    /** A link's target that leaves the manual, or stays on its page. */
    private const string ELSEWHERE = '/^([a-z][a-z0-9+.-]*:|#)/i';

    /** A Markdown link's target. */
    private const string LINK = '/\]\(([^)\s]+)\)/';

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
        foreach (LinkCheck::documents($manual) as $page) {
            $findings = [...$findings, ...self::links($manual, $page)];
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

    /**
     * The links of `$page` that leave the manual at `$manual` without a full URL.
     *
     * @return list<string>
     */
    private static function links(string $manual, string $page): array
    {
        preg_match_all(self::LINK, LinkCheck::prose($page), $links);
        $findings = [];
        foreach ($links[1] as $link) {
            $target = realpath(\dirname($page) . '/' . explode('#', $link, 2)[0]);
            $inside = $target !== false && str_starts_with(str_replace('\\', '/', $target), str_replace('\\', '/', (string) realpath($manual)));
            if (preg_match(self::ELSEWHERE, $link) !== 1 && !$inside) {
                $findings[] = "{$page}: the link {$link} leaves doc/; it is a full URL (R-DOC-7)";
            }
        }

        return $findings;
    }
}
