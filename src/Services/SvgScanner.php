<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use Nvl\Media\Exceptions\MediaUploadException;

/**
 * SvgScanner: validates uploaded SVG files against XSS and XXE threats.
 *
 * @internal
 */
final class SvgScanner
{
    /** @var array<int, string> Elements that always execute code or load external content. */
    private const DANGEROUS_ELEMENTS = [
        'script',
        'iframe',
        'object',
        'embed',
        'foreignobject',
        'handler',
        'animate',
        'animatetransform',
        'set',
    ];

    /** @var array<int, string> Elements safe with internal refs but dangerous with external ones. */
    private const EXTERNAL_REF_ELEMENTS = [
        'use',
        'image',
    ];

    /** @var array<int, string> Attributes that can contain executable URIs. */
    private const URI_ATTRIBUTES = [
        'href',
        'src',
        'action',
        'formaction',
    ];

    /**
     * Scan SVG file content for security threats.
     *
     * @throws MediaUploadException
     */
    public function scan(string $filePath): void
    {
        if (! config('nvl-media.svg_scanning', true)) {
            return;
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new MediaUploadException('Unable to read SVG file for security scanning.');
        }

        $this->scanRawContent($content);
        $this->scanDom($content);
    }

    /**
     * Pre-XML checks for patterns that must be caught before any XML parsing.
     *
     * @throws MediaUploadException
     */
    private function scanRawContent(string $content): void
    {
        if (preg_match('/<!ENTITY/i', $content)) {
            throw new MediaUploadException('SVG contains an ENTITY declaration, which is not allowed.');
        }

        if (preg_match('/<!DOCTYPE/i', $content)) {
            throw new MediaUploadException('SVG contains a DOCTYPE declaration, which is not allowed.');
        }

        if (preg_match('/<\?php/i', $content) || str_contains($content, '<?=')) {
            throw new MediaUploadException('SVG contains embedded PHP code, which is not allowed.');
        }

        if (preg_match('/<!\[CDATA\[/i', $content)) {
            throw new MediaUploadException('SVG contains a CDATA section, which is not allowed.');
        }

        if (preg_match('/@import\b/i', $content)) {
            throw new MediaUploadException('SVG contains a CSS @import rule, which is not allowed.');
        }
    }

    /**
     * Parse the SVG as XML and inspect elements and attributes.
     *
     * @throws MediaUploadException
     */
    private function scanDom(string $content): void
    {
        $prev_use_errors = libxml_use_internal_errors(true);

        $dom = new DOMDocument;
        $loaded = $dom->loadXML($content, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($prev_use_errors);

        if (! $loaded) {
            throw new MediaUploadException('SVG contains malformed XML and cannot be safely parsed.');
        }

        if ($dom->doctype !== null) {
            throw new MediaUploadException('SVG contains a DOCTYPE declaration, which is not allowed.');
        }

        $this->inspectNode($dom);
    }

    /**
     * Recursively inspect a DOM node and its children.
     *
     * @throws MediaUploadException
     */
    private function inspectNode(?DOMNode $node): void
    {
        if ($node === null) {
            return;
        }

        if ($node instanceof DOMProcessingInstruction) {
            throw new MediaUploadException('SVG contains a processing instruction, which is not allowed.');
        }

        if ($node instanceof DOMElement) {
            $this->checkElement($node);
            $this->checkAttributes($node);
        }

        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $this->inspectNode($child);
            }
        }
    }

    /**
     * Check if an element is dangerous.
     *
     * @throws MediaUploadException
     */
    private function checkElement(DOMElement $element): void
    {
        $local_name = strtolower((string) $element->localName);

        if (in_array($local_name, self::DANGEROUS_ELEMENTS, true)) {
            throw new MediaUploadException("SVG contains a forbidden <{$local_name}> element.");
        }

        if ($local_name === 'style') {
            $this->checkCssValue($element->textContent);
        }

        // <use> and <image> are safe with internal refs (#id) but dangerous with external URLs
        if (in_array($local_name, self::EXTERNAL_REF_ELEMENTS, true)) {
            $this->checkForExternalReference($element, $local_name);
        }

    }

    /**
     * Block <use> and <image> elements that reference external resources.
     * Internal references (href="#symbol-id") are safe.
     *
     * @throws MediaUploadException
     */
    private function checkForExternalReference(DOMElement $element, string $tagName): void
    {
        foreach ($element->attributes as $attr) {
            if (! in_array(strtolower($attr->localName ?? $attr->name), ['href', 'src'], true)) {
                continue;
            }
            $value = trim($attr->value);

            if ($value === '') {
                continue;
            }

            // Internal fragment references (#id) are safe
            if (str_starts_with($value, '#')) {
                continue;
            }

            // data: URIs checked separately via checkUriValue
            if (str_starts_with(strtolower($value), 'data:')) {
                continue;
            }

            throw new MediaUploadException(
                "SVG <{$tagName}> references an external resource, which is not allowed.",
            );
        }
    }

    /**
     * Check all attributes on an element for threats.
     *
     * @throws MediaUploadException
     */
    private function checkAttributes(DOMElement $element): void
    {
        /** @var DOMAttr $attr */
        foreach ($element->attributes as $attr) {
            $attr_name = strtolower($attr->localName ?? $attr->name);
            $attr_value = strtolower(trim($attr->value));

            if ($attr_name === 'base' && $attr->namespaceURI === 'http://www.w3.org/XML/1998/namespace') {
                throw new MediaUploadException('SVG contains an XML Base override, which is not allowed.');
            }

            $this->checkCssValue($attr_value);

            if (str_starts_with($attr_name, 'on')) {
                throw new MediaUploadException("SVG contains a forbidden event handler attribute [{$attr->name}].");
            }

            if (in_array($attr_name, self::URI_ATTRIBUTES, true)) {
                $this->checkUriValue($attr_value, $attr->name);
            }
        }
    }

    /** Reject external CSS resources and obfuscation without rewriting uploaded bytes. */
    private function checkCssValue(string $value): void
    {
        $withoutLocalReferences = preg_replace('~url\(\s*(["\']?)#[a-z0-9_.:-]+\1\s*\)~i', '', $value) ?? $value;
        if (preg_match('~@import\b|url\s*\(|\\\\|/\*~i', $withoutLocalReferences) === 1) {
            throw new MediaUploadException('SVG contains external or obfuscated CSS, which is not allowed.');
        }
    }

    /**
     * Check a URI attribute value for dangerous schemes.
     *
     * @param  string  $value  Lowercased, trimmed attribute value
     * @param  string  $attrName  Original attribute name for error messages
     *
     * @throws MediaUploadException
     */
    private function checkUriValue(string $value, string $attrName): void
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new MediaUploadException("SVG contains control characters in [{$attrName}] URI attribute.");
        }

        if (str_starts_with($value, 'javascript:')) {
            throw new MediaUploadException("SVG contains a javascript: URI in [{$attrName}] attribute.");
        }

        if (str_starts_with($value, 'data:') && ! $this->isSafeDataUri($value)) {
            throw new MediaUploadException("SVG contains a potentially dangerous data: URI in [{$attrName}] attribute.");
        }

        if (str_starts_with($value, 'vbscript:')) {
            throw new MediaUploadException("SVG contains a vbscript: URI in [{$attrName}] attribute.");
        }
    }

    /**
     * Determine if a data: URI is safe (only images are allowed).
     */
    private function isSafeDataUri(string $value): bool
    {
        return (bool) preg_match('/^data:image\/(png|jpeg|jpg|gif|webp|avif)[;,]/', $value);
    }
}
