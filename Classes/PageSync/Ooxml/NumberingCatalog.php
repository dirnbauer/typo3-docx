<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

/**
 * Which list levels of numbering.xml are numbered and which are bulleted.
 */
final class NumberingCatalog
{
    /**
     * @param array<int, array<int, string>> $formats numId => level => numFmt
     */
    private function __construct(
        private readonly array $formats,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromXml(\DOMDocument $document): self
    {
        $abstract = [];
        foreach ($document->getElementsByTagNameNS(Ns::W, 'abstractNum') as $abstractNum) {
            $abstractId = (int)$abstractNum->getAttributeNS(Ns::W, 'abstractNumId');
            $levels = [];
            foreach (SafeXml::children($abstractNum) as $level) {
                if ($level->localName !== 'lvl' || $level->namespaceURI !== Ns::W) {
                    continue;
                }
                $format = SafeXml::wordValue(SafeXml::firstChild($level, Ns::W, 'numFmt')) ?? 'bullet';
                $levels[(int)$level->getAttributeNS(Ns::W, 'ilvl')] = $format;
            }
            $abstract[$abstractId] = $levels;
        }

        $formats = [];
        foreach ($document->getElementsByTagNameNS(Ns::W, 'num') as $num) {
            $numId = (int)$num->getAttributeNS(Ns::W, 'numId');
            $abstractId = SafeXml::wordValue(SafeXml::firstChild($num, Ns::W, 'abstractNumId'));
            $levels = $abstractId !== null ? ($abstract[(int)$abstractId] ?? []) : [];
            // A level override can swap the format of single levels.
            foreach (SafeXml::children($num) as $override) {
                if ($override->localName !== 'lvlOverride') {
                    continue;
                }
                $level = SafeXml::firstChild($override, Ns::W, 'lvl');
                $format = $level === null ? null : SafeXml::wordValue(SafeXml::firstChild($level, Ns::W, 'numFmt'));
                if ($format !== null) {
                    $levels[(int)$override->getAttributeNS(Ns::W, 'ilvl')] = $format;
                }
            }
            $formats[$numId] = $levels;
        }

        return new self($formats);
    }

    public function isKnown(int $numId): bool
    {
        return isset($this->formats[$numId]);
    }

    public function isOrdered(int $numId, int $level): bool
    {
        $format = $this->formats[$numId][$level] ?? $this->formats[$numId][0] ?? 'bullet';

        return !in_array($format, ['bullet', 'none', ''], true);
    }
}
