<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * Every relative link of the documents resolves (RULES.md R-DOC-7): each
 * `.md` file's links to other files of the repository name a file or a
 * directory that exists, since turborel.com's documentation site builds
 * its pages from `doc/` and rewrites those links.
 */
final class LinkCheck
{
    /** A Markdown link's target: what follows `](`, to its `)`. */
    private const string LINK = '/\]\(([^)\s]+)\)/';

    /** A target that leaves the repository, or stays on its page. */
    private const string ELSEWHERE = '/^([a-z][a-z0-9+.-]*:|#)/i';

    /** A fenced block of code, whose text holds no links. */
    private const string FENCE = '/^```.*?^```/ms';

    /** The directories whose documents are not the repository's own. */
    private const array SKIPPED = ['vendor', 'build', '.git', '.claude'];

    /** A document's extension. */
    private const string EXTENSION = 'md';

    /**
     * What breaks R-DOC-7's links in the documents under `$root`.
     *
     * @return list<string>
     */
    public static function check(string $root): array
    {
        $findings = [];
        foreach (self::documents($root) as $document) {
            preg_match_all(self::LINK, self::prose($document), $links);
            foreach ($links[1] as $link) {
                $path = explode('#', $link, 2)[0];
                if (preg_match(self::ELSEWHERE, $link) === 1 || file_exists(\dirname($document) . '/' . $path)) {
                    continue;
                }
                $findings[] = "{$document}: the link {$link} names no file (R-DOC-7)";
            }
        }

        return $findings;
    }

    /** The text of the document `$document`, its fenced blocks of code left out. */
    public static function prose(string $document): string
    {
        return (string) preg_replace(self::FENCE, '', Sources::read($document));
    }

    /**
     * The documents under `$root`, sorted.
     *
     * @return list<string>
     */
    public static function documents(string $root): array
    {
        $filter = static fn(\SplFileInfo $file): bool => !\in_array($file->getFilename(), self::SKIPPED, true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            $filter,
        ));
        $documents = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === self::EXTENSION) {
                $documents[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($documents);

        return $documents;
    }
}
