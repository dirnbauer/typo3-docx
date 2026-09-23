<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;

/**
 * Parses package parts as untrusted XML: no document type declarations (so no entity
 * expansion and no external entities), no network access, and libxml's default size limits.
 */
final class SafeXml
{
    public static function load(string $xml, string $partName): \DOMDocument
    {
        // OOXML parts never carry a DTD. Refusing one outright closes entity expansion
        // ("billion laughs") and external entity loading before libxml sees the bytes.
        if (preg_match('/<!DOCTYPE/i', $xml) === 1 || preg_match('/<!ENTITY/i', $xml) === 1) {
            throw new PageSyncException('error.unsafeXml', 422, [$partName]);
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->documentElement === null) {
            throw new PageSyncException('error.brokenXml', 422, [$partName]);
        }

        return $document;
    }

    public static function xpath(\DOMDocument $document): \DOMXPath
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', Ns::W);
        $xpath->registerNamespace('r', Ns::R);
        $xpath->registerNamespace('wp', Ns::WP);
        $xpath->registerNamespace('a', Ns::A);
        $xpath->registerNamespace('pic', Ns::PIC);
        $xpath->registerNamespace('v', Ns::V);
        $xpath->registerNamespace('mc', Ns::MC);
        $xpath->registerNamespace('rel', Ns::PACKAGE_RELATIONSHIPS);
        $xpath->registerNamespace('ct', Ns::CONTENT_TYPES);

        return $xpath;
    }

    /**
     * The child elements of a node, in document order.
     *
     * @return list<\DOMElement>
     */
    public static function children(\DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    public static function firstChild(\DOMNode $node, string $namespace, string $localName): ?\DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $localName && $child->namespaceURI === $namespace) {
                return $child;
            }
        }

        return null;
    }

    /**
     * The w:val attribute of a WordprocessingML property element such as <w:pStyle w:val="..."/>.
     */
    public static function wordValue(?\DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }
        if ($element->hasAttributeNS(Ns::W, 'val')) {
            return $element->getAttributeNS(Ns::W, 'val');
        }

        return null;
    }

    /**
     * An on/off property (<w:b/>, <w:b w:val="0"/>) — present and not switched off.
     */
    public static function isOn(?\DOMElement $element): bool
    {
        if ($element === null) {
            return false;
        }
        $value = self::wordValue($element);

        return $value === null || !in_array(strtolower($value), ['0', 'false', 'off', 'none'], true);
    }
}
