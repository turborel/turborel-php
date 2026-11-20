<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\Quietly;
use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * An XML value, as its serialization as CONTENT [PROTOCOL Values]: read
 * as its text, or parsed for its nodes; a parameter made of its text
 * [CLIENT XML values].
 */
final readonly class Xml implements SqlValue
{
    /** Makes it of `$text`. */
    public function __construct(
        /** Its serialization, UTF-8. */
        public string $text,
    ) {}

    /**
     * Its nodes, parsed from its text as XML content by PHP's DOM
     * extension, in a document of their own: elements, text, comments and
     * processing instructions, in order.
     *
     * @return list<\DOMNode>
     *
     * @throws TurborelException SQLSTATE 2200N if the text is not well-formed XML content, or the DOM extension is not loaded
     */
    public function nodes(): array
    {
        if (!class_exists(\DOMDocument::class)) {
            throw ValueFailure::invalidXmlContent('the DOM extension, which parses an XML value, is not loaded');
        }
        if ($this->text === '') {
            return [];
        }
        $document = new \DOMDocument();
        $fragment = $document->createDocumentFragment();
        $internal = libxml_use_internal_errors(true);
        [$parsed] = Quietly::call(fn(): bool => $fragment->appendXML($this->text));
        $error = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($internal);
        if ($parsed !== true) {
            throw ValueFailure::invalidXmlContent('the text is not well-formed XML content: ' . ($error === false ? 'it does not parse' : trim($error->message)));
        }

        return array_values(iterator_to_array($fragment->childNodes));
    }
}
