<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;

/**
 * The styles and settings parts exported documents are built with.
 *
 * By default the extension's own template; a site can point the extension setting
 * "pageSync.wordTemplate" at a corporate .dotx or .docx and its styles are used instead. Styles
 * the writer needs and the template lacks are added from the default template, and every
 * logical style is mapped to the template's own style id by the built-in name, because Word
 * localises style ids ("berschrift1").
 */
final readonly class WordTemplate
{
    /**
     * Logical style => built-in (English, lower-case) style name.
     */
    private const array REQUIRED = [
        'normal' => 'normal',
        'title' => 'title',
        'subtitle' => 'subtitle',
        'heading1' => 'heading 1',
        'heading2' => 'heading 2',
        'heading3' => 'heading 3',
        'heading4' => 'heading 4',
        'heading5' => 'heading 5',
        'heading6' => 'heading 6',
        'quote' => 'quote',
        'quoteSource' => 'quote source',
        'caption' => 'caption',
        'code' => 'source code',
        'codeChar' => 'source code char',
        'listParagraph' => 'list paragraph',
        'hyperlink' => 'hyperlink',
        'summary' => 'typo3 summary',
        'tableGrid' => 'table grid',
    ];

    /**
     * @param array<string, string> $styleIds Logical style => style id in stylesXml
     */
    private function __construct(
        public string $stylesXml,
        public string $settingsXml,
        private array $styleIds,
    ) {}

    /**
     * @param string $templatePath An EXT: path or project path to a .dotx/.docx; "" for the default
     * @param string $languageTag  BCP 47 tag the proofing tools should use, e.g. "de-AT"
     */
    public static function load(string $templatePath = '', string $languageTag = ''): self
    {
        $defaultDirectory = dirname(__DIR__, 3) . '/Resources/Private/Word/';
        $defaultStyles = (string)file_get_contents($defaultDirectory . 'styles.xml');
        $settings = (string)file_get_contents($defaultDirectory . 'settings.xml');

        $styles = $defaultStyles;
        if ($templatePath !== '') {
            $styles = self::stylesOfTemplate($templatePath);
        }

        $document = SafeXml::load($styles, 'word/styles.xml');
        $defaults = SafeXml::load($defaultStyles, 'default styles');
        $styleIds = self::completeStyles($document, $defaults);
        if ($languageTag !== '') {
            self::setLanguage($document, $languageTag);
        }

        return new self((string)$document->saveXML(), $settings, $styleIds);
    }

    public function styleId(string $logicalStyle): string
    {
        return $this->styleIds[$logicalStyle] ?? ucfirst($logicalStyle);
    }

    private static function stylesOfTemplate(string $templatePath): string
    {
        $absolutePath = GeneralUtility::getFileAbsFileName($templatePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            throw new PageSyncException('error.templateMissing', 500, [$templatePath]);
        }
        $archive = DocxArchive::fromBinary((string)file_get_contents($absolutePath));
        $mainPart = $archive->mainDocumentPart();
        $relationship = $archive->firstRelationshipOfType($mainPart, Ns::REL_STYLES);
        if ($relationship === null || !$archive->has($relationship->target)) {
            throw new PageSyncException('error.templateWithoutStyles', 500, [$templatePath]);
        }

        return $archive->read($relationship->target);
    }

    /**
     * Maps every logical style to a style of the template, importing the ones it lacks.
     *
     * @return array<string, string>
     */
    private static function completeStyles(\DOMDocument $styles, \DOMDocument $defaults): array
    {
        $root = $styles->documentElement;
        if ($root === null) {
            throw new PageSyncException('error.brokenXml', 500, ['word/styles.xml']);
        }
        $byName = self::stylesByName($styles);
        $ids = array_fill_keys(array_keys(self::stylesById($styles)), true);
        $defaultsByName = self::stylesByName($defaults);

        $map = [];
        foreach (self::REQUIRED as $logical => $name) {
            if (isset($byName[$name])) {
                $map[$logical] = $byName[$name]->getAttributeNS(Ns::W, 'styleId');
                continue;
            }
            $source = $defaultsByName[$name] ?? null;
            if ($source === null) {
                continue;
            }
            $imported = $styles->importNode($source, true);
            if (!$imported instanceof \DOMElement) {
                continue;
            }
            $id = $imported->getAttributeNS(Ns::W, 'styleId');
            if (isset($ids[$id])) {
                $id = 'TYPO3' . $id;
                $imported->setAttributeNS(Ns::W, 'w:styleId', $id);
            }
            // An imported style is based on "Normal" of the template, whatever its id is there.
            $basedOn = SafeXml::firstChild($imported, Ns::W, 'basedOn');
            if ($basedOn !== null && SafeXml::wordValue($basedOn) === 'Normal' && isset($byName['normal'])) {
                $basedOn->setAttributeNS(Ns::W, 'w:val', $byName['normal']->getAttributeNS(Ns::W, 'styleId'));
            }
            $root->appendChild($imported);
            $ids[$id] = true;
            $map[$logical] = $id;
        }

        return $map;
    }

    /**
     * @return array<string, \DOMElement> lower-case built-in name => w:style
     */
    private static function stylesByName(\DOMDocument $styles): array
    {
        $byName = [];
        foreach ($styles->getElementsByTagNameNS(Ns::W, 'style') as $style) {
            $name = strtolower(trim(SafeXml::wordValue(SafeXml::firstChild($style, Ns::W, 'name')) ?? ''));
            if ($name !== '' && !isset($byName[$name])) {
                $byName[$name] = $style;
            }
        }

        return $byName;
    }

    /**
     * @return array<string, \DOMElement>
     */
    private static function stylesById(\DOMDocument $styles): array
    {
        $byId = [];
        foreach ($styles->getElementsByTagNameNS(Ns::W, 'style') as $style) {
            $byId[$style->getAttributeNS(Ns::W, 'styleId')] = $style;
        }

        return $byId;
    }

    private static function setLanguage(\DOMDocument $styles, string $languageTag): void
    {
        $languageTag = (string)preg_replace('/[^A-Za-z0-9-]/', '', str_replace('_', '-', $languageTag));
        if ($languageTag === '') {
            return;
        }
        foreach ($styles->getElementsByTagNameNS(Ns::W, 'rPrDefault') as $defaultRun) {
            $properties = SafeXml::firstChild($defaultRun, Ns::W, 'rPr');
            if ($properties === null) {
                $properties = $styles->createElementNS(Ns::W, 'w:rPr');
                $defaultRun->appendChild($properties);
            }
            $language = SafeXml::firstChild($properties, Ns::W, 'lang');
            if ($language === null) {
                $language = $styles->createElementNS(Ns::W, 'w:lang');
                $properties->appendChild($language);
            }
            $language->setAttributeNS(Ns::W, 'w:val', $languageTag);

            return;
        }
    }
}
