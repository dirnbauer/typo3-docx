<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

/**
 * The paragraph and character styles of a document, reduced to their meaning.
 *
 * Styles are recognised by their built-in name ("heading 2", "Quote"), which Word writes in
 * English whatever the UI language — the style ids are localised ("berschrift2" in German
 * Word), so they cannot be trusted. Outline levels and basedOn chains are followed, so a
 * custom "Chapter" style based on Heading 1 is a heading too.
 */
final class StyleCatalog
{
    private const array MONOSPACE_FONTS = [
        'courier', 'courier new', 'consolas', 'menlo', 'monaco', 'lucida console', 'lucida sans typewriter',
        'source code pro', 'dejavu sans mono', 'liberation mono', 'fira code', 'fira mono', 'jetbrains mono',
        'roboto mono', 'ubuntu mono', 'sf mono', 'cascadia code', 'cascadia mono', 'andale mono', 'ibm plex mono',
    ];

    /**
     * @param array<string, array{name: string, type: string, basedOn: string, outline: int, numId: int, ilvl: int, mono: bool}> $styles Keyed by style id
     */
    private function __construct(
        private readonly array $styles,
        private readonly string $defaultParagraphStyle,
    ) {}

    public static function empty(): self
    {
        return new self([], '');
    }

    public static function fromXml(\DOMDocument $document): self
    {
        $styles = [];
        $default = '';
        foreach ($document->getElementsByTagNameNS(Ns::W, 'style') as $style) {
            $id = $style->getAttributeNS(Ns::W, 'styleId');
            if ($id === '') {
                continue;
            }
            $type = $style->getAttributeNS(Ns::W, 'type');
            $name = SafeXml::wordValue(SafeXml::firstChild($style, Ns::W, 'name')) ?? $id;
            $basedOn = SafeXml::wordValue(SafeXml::firstChild($style, Ns::W, 'basedOn')) ?? '';
            $pPr = SafeXml::firstChild($style, Ns::W, 'pPr');
            $outline = -1;
            $numId = -1;
            $ilvl = 0;
            if ($pPr !== null) {
                $outlineValue = SafeXml::wordValue(SafeXml::firstChild($pPr, Ns::W, 'outlineLvl'));
                $outline = $outlineValue !== null && is_numeric($outlineValue) ? (int)$outlineValue : -1;
                $numPr = SafeXml::firstChild($pPr, Ns::W, 'numPr');
                if ($numPr !== null) {
                    $numIdValue = SafeXml::wordValue(SafeXml::firstChild($numPr, Ns::W, 'numId'));
                    $numId = $numIdValue !== null && is_numeric($numIdValue) ? (int)$numIdValue : -1;
                    $ilvlValue = SafeXml::wordValue(SafeXml::firstChild($numPr, Ns::W, 'ilvl'));
                    $ilvl = $ilvlValue !== null && is_numeric($ilvlValue) ? (int)$ilvlValue : 0;
                }
            }
            $rPr = SafeXml::firstChild($style, Ns::W, 'rPr');
            $styles[$id] = [
                'name' => strtolower(trim($name)),
                'type' => $type,
                'basedOn' => $basedOn,
                'outline' => $outline,
                'numId' => $numId,
                'ilvl' => $ilvl,
                'mono' => $rPr !== null && self::isMonospaceRunProperties($rPr),
            ];
            if ($type === 'paragraph' && in_array(strtolower($style->getAttributeNS(Ns::W, 'default')), ['1', 'true', 'on'], true)) {
                $default = $id;
            }
        }

        return new self($styles, $default);
    }

    public function defaultParagraphStyle(): string
    {
        return $this->defaultParagraphStyle;
    }

    /**
     * The role of a paragraph style and, for a heading, its level (1–6).
     *
     * @return array{0: StyleRole, 1: int}
     */
    public function paragraphRole(string $styleId): array
    {
        $visited = [];
        $current = $styleId;
        while ($current !== '' && !isset($visited[$current])) {
            $visited[$current] = true;
            $style = $this->styles[$current] ?? null;
            if ($style === null) {
                break;
            }
            $byName = self::roleByName($style['name']);
            if ($byName !== null) {
                return $byName;
            }
            if ($style['outline'] >= 0 && $style['outline'] <= 8) {
                return [StyleRole::Heading, min(6, $style['outline'] + 1)];
            }
            if ($style['mono']) {
                return [StyleRole::Code, 0];
            }
            $current = $style['basedOn'];
        }

        // Unknown or localised ids that still spell a known style ("Heading2" from generators
        // that write no styles part at all).
        return self::roleByName(strtolower($styleId)) ?? [StyleRole::Body, 0];
    }

    /**
     * Numbering a paragraph style carries itself ("List Bullet", numbered headings).
     *
     * @return array{0: int, 1: int}|null numId and level
     */
    public function styleNumbering(string $styleId): ?array
    {
        $visited = [];
        $current = $styleId;
        while ($current !== '' && !isset($visited[$current])) {
            $visited[$current] = true;
            $style = $this->styles[$current] ?? null;
            if ($style === null) {
                return null;
            }
            if ($style['numId'] >= 0) {
                return $style['numId'] === 0 ? null : [$style['numId'], $style['ilvl']];
            }
            $current = $style['basedOn'];
        }

        return null;
    }

    /**
     * What a character style contributes to the marks of a run.
     *
     * @return array{bold: bool, italic: bool, code: bool}
     */
    public function characterMarks(string $styleId): array
    {
        $marks = ['bold' => false, 'italic' => false, 'code' => false];
        $visited = [];
        $current = $styleId;
        while ($current !== '' && !isset($visited[$current])) {
            $visited[$current] = true;
            $style = $this->styles[$current] ?? null;
            $name = $style['name'] ?? strtolower($current);
            if (in_array($name, ['strong', 'intense emphasis', 'book title'], true)) {
                $marks['bold'] = true;
            }
            if (in_array($name, ['emphasis', 'subtle emphasis', 'intense emphasis'], true)) {
                $marks['italic'] = true;
            }
            if (str_contains($name, 'code') || str_contains($name, 'verbatim') || in_array($name, ['html code', 'html keyboard', 'html typewriter', 'html sample'], true) || ($style['mono'] ?? false)) {
                $marks['code'] = true;
            }
            if ($style === null) {
                break;
            }
            $current = $style['basedOn'];
        }

        return $marks;
    }

    public static function isMonospaceRunProperties(\DOMElement $rPr): bool
    {
        $fonts = SafeXml::firstChild($rPr, Ns::W, 'rFonts');
        if ($fonts === null) {
            return false;
        }
        $font = $fonts->getAttributeNS(Ns::W, 'ascii');
        if ($font === '') {
            $font = $fonts->getAttributeNS(Ns::W, 'hAnsi');
        }

        return $font !== '' && in_array(strtolower(trim($font)), self::MONOSPACE_FONTS, true);
    }

    /**
     * @return array{0: StyleRole, 1: int}|null
     */
    private static function roleByName(string $name): ?array
    {
        $name = strtolower(trim($name));
        if (preg_match('/^heading\s*([1-9])$/', $name, $matches) === 1) {
            return [StyleRole::Heading, min(6, (int)$matches[1])];
        }
        if (preg_match('/^toc\s*([1-9]|heading)$/', $name) === 1 || $name === 'toc heading') {
            return [StyleRole::TableOfContents, 0];
        }

        return match ($name) {
            'title' => [StyleRole::Title, 1],
            'subtitle' => [StyleRole::Subtitle, 0],
            'quote', 'intense quote', 'block text' => [StyleRole::Quote, 0],
            'quote source' => [StyleRole::QuoteSource, 0],
            'caption', 'table caption', 'image caption' => [StyleRole::Caption, 0],
            'source code', 'html preformatted', 'plain text', 'code', 'code block', 'macro text' => [StyleRole::Code, 0],
            default => null,
        };
    }
}
