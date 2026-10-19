<?php

namespace App\Support\Images;

use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;

/**
 * Refuses SVG logos with active content or external references, never alters them (feature 013
 * research R4, FR-016). The document is parsed with libxml without network access, entity
 * substitution or DTD loading, and the first violation in document order is reported with its
 * construct and line:
 *
 *  - `notWellFormed`, `rootNotSvg` (the root must be `svg` in the SVG namespace);
 *  - `doctype` (any document type declaration, which covers entities and external DTDs);
 *  - `styleImport` (processing instructions such as `xml-stylesheet`, and CSS `@import`);
 *  - `scriptElement` (`script` in any namespace);
 *  - `eventHandlerAttribute` (an attribute whose name starts with `on`);
 *  - `foreignContent` (`foreignObject`, XHTML elements and elements without a namespace;
 *    editor metadata in other namespaces is inert and accepted);
 *  - `scriptUrl` (a `javascript:`, `vbscript:` or `data:text/html` address, CSS `expression(`, or an
 *    animation that targets `href` or an event handler);
 *  - `externalReference` (any `href`, `src` or CSS `url()` that is neither an in-document reference
 *    `#id` nor embedded PNG, JPEG, GIF or WebP data; embedded SVG data included).
 */
class SvgSafetyInspector
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';

    /** Presentation attributes that may hold a CSS `url()`. */
    private const URL_ATTRIBUTES = ['fill', 'stroke', 'filter', 'clip-path', 'mask', 'marker', 'marker-start', 'marker-mid', 'marker-end', 'cursor', 'style'];

    private const ANIMATIONS = ['animate', 'set', 'animatetransform', 'animatemotion'];

    /**
     * The first violation, or null when the SVG is safe.
     *
     * @return array{construct: string, line: int}|null
     */
    public function inspect(string $bytes): ?array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOCDATA);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $errors !== [] || $document->documentElement === null) {
            $first = $errors[0] ?? null;

            return self::violation('notWellFormed', $first === null ? 1 : $first->line);
        }

        if ($document->doctype !== null) {
            return self::violation('doctype', self::lineOf($bytes, '<!DOCTYPE'));
        }

        foreach ($document->childNodes as $node) {
            if ($node instanceof DOMProcessingInstruction) {
                return self::violation('styleImport', $node->getLineNo());
            }
        }

        $root = $document->documentElement;

        if ($root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            return self::violation('rootNotSvg', $root->getLineNo());
        }

        return $this->element($root);
    }

    /**
     * @return array{construct: string, line: int}|null
     */
    private function element(DOMElement $element): ?array
    {
        $line = $element->getLineNo();
        $name = strtolower((string) $element->localName);
        $namespace = $element->namespaceURI;

        if ($name === 'script') {
            return self::violation('scriptElement', $line);
        }

        if ($name === 'foreignobject' || $namespace === self::XHTML_NS || $namespace === null) {
            return self::violation('foreignContent', $line);
        }

        foreach ($element->attributes ?? [] as $attribute) {
            $attributeName = strtolower((string) $attribute->localName);
            $value = (string) $attribute->value;

            if (str_starts_with($attributeName, 'on')) {
                return self::violation('eventHandlerAttribute', $line);
            }

            if (in_array($attributeName, ['href', 'src'], true) && ($construct = self::reference($value)) !== null) {
                return self::violation($construct, $line);
            }

            if (in_array($attributeName, self::URL_ATTRIBUTES, true) && ($construct = self::css($value)) !== null) {
                return self::violation($construct, $line);
            }

            if ($attributeName === 'attributename' && $namespace === self::SVG_NS && in_array($name, self::ANIMATIONS, true)) {
                $target = strtolower(preg_replace('/^.*:/', '', trim($value)) ?? '');

                if ($target === 'href' || str_starts_with($target, 'on')) {
                    return self::violation('scriptUrl', $line);
                }
            }
        }

        if ($name === 'style' && ($construct = self::css((string) $element->textContent)) !== null) {
            return self::violation($construct, $line);
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMProcessingInstruction) {
                return self::violation('styleImport', $child->getLineNo());
            }

            if ($child instanceof DOMElement && ($violation = $this->element($child)) !== null) {
                return $violation;
            }
        }

        return null;
    }

    /** The construct of a CSS text, or null when it is safe. */
    private static function css(string $css): ?string
    {
        if (preg_match('/@import/i', $css) === 1) {
            return 'styleImport';
        }

        if (preg_match('/expression\s*\(/i', $css) === 1) {
            return 'scriptUrl';
        }

        preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', $css, $matches);

        foreach ($matches[2] as $target) {
            if (($construct = self::reference(html_entity_decode($target, ENT_QUOTES | ENT_HTML5))) !== null) {
                return $construct;
            }
        }

        return null;
    }

    /** The construct of a referenced address, or null for `#id` and embedded raster images. */
    private static function reference(string $address): ?string
    {
        $address = trim($address);
        $normalised = strtolower(preg_replace('/[\x00-\x20]+/', '', $address) ?? '');

        if (str_starts_with($address, '#')) {
            return null;
        }

        if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,#', $normalised) === 1) {
            return null;
        }

        if (str_starts_with($normalised, 'javascript:') || str_starts_with($normalised, 'vbscript:') || str_starts_with($normalised, 'data:text/html')) {
            return 'scriptUrl';
        }

        return 'externalReference';
    }

    /**
     * @return array{construct: string, line: int}
     */
    private static function violation(string $construct, int $line): array
    {
        return ['construct' => $construct, 'line' => max(1, $line)];
    }

    private static function lineOf(string $bytes, string $needle): int
    {
        $position = stripos($bytes, $needle);

        return $position === false ? 1 : substr_count($bytes, "\n", 0, $position) + 1;
    }
}
