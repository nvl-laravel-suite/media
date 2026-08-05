<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use Nvl\Media\Exceptions\MediaUploadException;

/** SvgScanner: validates uploaded SVG files against XSS and XXE threats. */
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
        'xlink:href',
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
        if (! config('media.svg_scanning', true)) {
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
    private function scanRawContent(string &$content): void
    {
        // Strip XML declaration and DOCTYPE — many legitimate SVGs from design tools include these.
        // ENTITY declarations remain blocked as they enable XXE attacks.
        $content = preg_replace('/\s*<\?xml[^?]*\?>\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*<!DOCTYPE[^>]*>\s*/i', '', $content) ?? $content;

        if (preg_match('/<!ENTITY/i', $content)) {
            throw new MediaUploadException('SVG contains an ENTITY declaration, which is not allowed.');
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
        $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOENT);

        libxml_clear_errors();
        libxml_use_internal_errors($prev_use_errors);

        if (! $loaded) {
            throw new MediaUploadException('SVG contains malformed XML and cannot be safely parsed.');
        }

        $this->inspectNode($dom->documentElement);
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

        // <use> and <image> are safe with internal refs (#id) but dangerous with external URLs
        if (in_array($local_name, self::EXTERNAL_REF_ELEMENTS, true)) {
            $this->checkForExternalReference($element, $local_name);
        }

        // <style> is allowed but must not contain @import (already caught in raw scan);
        // inline <style> with event-like content is blocked via attribute checks
    }

    /**
     * Block <use> and <image> elements that reference external resources.
     * Internal references (href="#symbol-id") are safe.
     *
     * @throws MediaUploadException
     */
    private function checkForExternalReference(DOMElement $element, string $tagName): void
    {
        foreach (['href', 'xlink:href', 'src'] as $attr) {
            $value = trim($element->getAttribute($attr));

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
                "SVG <{$tagName}> references an external resource [{$value}], which is not allowed.",
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
            $attr_name = strtolower($attr->name);
            $attr_value = strtolower(trim($attr->value));

            if (str_starts_with($attr_name, 'on')) {
                throw new MediaUploadException("SVG contains a forbidden event handler attribute [{$attr->name}].");
            }

            if (in_array($attr_name, self::URI_ATTRIBUTES, true)) {
                $this->checkUriValue($attr_value, $attr->name);
            }
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
        return (bool) preg_match('/^data:image\/(png|jpeg|jpg|gif|webp|avif|svg\+xml)[;,]/', $value);
    }
}
