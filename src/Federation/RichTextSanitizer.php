<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Sanitises rich text to the wire schema's restricted HTML subset before
 * rendering or serving. Consumers MUST sanitise descriptions regardless of
 * what the authority sent (LS-5); authorities restrict on input (LS-4).
 * Allowed elements are kept, dangerous elements are removed with their
 * content, everything else is unwrapped to its text, and the only
 * attribute that survives is an https href on links. Not wp_kses: the
 * unwrap-vs-drop distinction and the self-promotion host stripping are
 * part of the contract.
 *
 * // listing-schema.md §Conventions (7), §Descriptions
 */
class RichTextSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'h3', 'h4', 'a'];

    private const DROPPED_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form'];

    /** @var list<string> */
    private array $stripLinkHosts = [];

    /**
     * @param list<string> $stripLinkHosts hosts whose links are removed
     *                                     entirely (self-promotion guard:
     *                                     descriptions travel to partner
     *                                     websites and must not link back
     *                                     to the authority's own sites)
     */
    public function sanitize(string $html, array $stripLinkHosts = []): string
    {
        $this->stripLinkHosts = array_map(
            static fn (string $host): string => strtolower(preg_replace('/^www\./', '', $host) ?? $host),
            $stripLinkHosts,
        );

        if (trim($html) === '') {
            return '';
        }

        libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NONET,
        );

        libxml_clear_errors();

        $root = $document->getElementById('__root');

        if ($root === null) {
            return '';
        }

        $this->sanitizeChildren($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        // Iterate over a static list: sanitising mutates the live child list.
        foreach (iterator_to_array($node->childNodes) as $child) {
            // Plain text is safe — saveHTML() escapes it. Everything else
            // that is not an element (comments, processing instructions,
            // CDATA) is dropped rather than passed through: this parser is
            // libxml (HTML4) while browsers tokenise as HTML5, and the two
            // disagree on where a comment ends. Re-serialising a comment
            // verbatim lets `<!--><img onerror=…>-->` smuggle a live element
            // past the allow-list on some PHP versions (mXSS).
            if (! $child instanceof DOMElement) {
                if (! $child instanceof DOMText) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrap($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeChildren($child);
        }
    }

    /**
     * Replace a disallowed element with its (sanitised) children.
     */
    private function unwrap(DOMElement $element): void
    {
        $this->sanitizeChildren($element);

        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $href = $element->getAttribute('href');

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $element->removeAttribute($attribute->nodeName);
        }

        if ($tag === 'a' && str_starts_with($href, 'https://') && ! $this->linksToStrippedHost($href)) {
            $element->setAttribute('href', $href);
            $element->setAttribute('rel', 'noopener noreferrer');
            $element->setAttribute('target', '_blank');
        }
    }

    private function linksToStrippedHost(string $href): bool
    {
        $host = parse_url($href, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);

        return in_array($host, $this->stripLinkHosts, true);
    }
}
