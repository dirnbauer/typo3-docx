<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Fixtures\PageSync;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds small .docx packages from hand-written WordprocessingML, the way other producers write
 * them — for tests of what the reader makes of constructs the writer never produces.
 */
final class DocxFixtureBuilder
{
    public const string W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const string R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private string $styles = '';
    private string $numbering = '';

    /** @var array<string, string> */
    private array $extraParts = [];

    /** @var list<array{id: string, type: string, target: string, external: bool}> */
    private array $relationships = [];

    public function __construct(
        private readonly string $body,
    ) {}

    public static function body(string $body): self
    {
        return new self($body);
    }

    public function withStyles(string $styles): self
    {
        $this->styles = '<w:styles xmlns:w="' . self::W . '">' . $styles . '</w:styles>';

        return $this;
    }

    public function withNumbering(string $numbering): self
    {
        $this->numbering = '<w:numbering xmlns:w="' . self::W . '">' . $numbering . '</w:numbering>';

        return $this;
    }

    public function withRelationship(string $id, string $type, string $target, bool $external = false): self
    {
        $this->relationships[] = ['id' => $id, 'type' => $type, 'target' => $target, 'external' => $external];

        return $this;
    }

    public function withPart(string $name, string $contents): self
    {
        $this->extraParts[$name] = $contents;

        return $this;
    }

    public function withImage(string $relationshipId, string $partName, string $bytes): self
    {
        $this->extraParts['word/' . $partName] = $bytes;

        return $this->withRelationship($relationshipId, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', $partName);
    }

    public function build(): string
    {
        $relationships = $this->relationships;
        $parts = [
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"'
                . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
                . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
                . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
                . ' xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"'
                . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
                . ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
                . ' xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math">'
                . '<w:body>' . $this->body . '</w:body></w:document>',
        ];
        if ($this->styles !== '') {
            $parts['word/styles.xml'] = $this->styles;
            $relationships[] = ['id' => 'rIdStyles', 'type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'target' => 'styles.xml', 'external' => false];
        }
        if ($this->numbering !== '') {
            $parts['word/numbering.xml'] = $this->numbering;
            $relationships[] = ['id' => 'rIdNumbering', 'type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering', 'target' => 'numbering.xml', 'external' => false];
        }
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($relationships as $relationship) {
            $rels .= '<Relationship Id="' . $relationship['id'] . '" Type="' . $relationship['type'] . '" Target="'
                . htmlspecialchars($relationship['target'], ENT_XML1 | ENT_QUOTES) . '"'
                . ($relationship['external'] ? ' TargetMode="External"' : '') . '/>';
        }
        $parts['word/_rels/document.xml.rels'] = $rels . '</Relationships>';
        $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
        $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        return self::zip($parts + $this->extraParts);
    }

    /**
     * @param array<string, string> $parts
     */
    public static function zip(array $parts): string
    {
        $file = GeneralUtility::tempnam('docx_fixture_');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($parts as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $binary = (string)file_get_contents($file);
        unlink($file);

        return $binary;
    }

    /**
     * A valid PNG, one pixel unless sized otherwise, tinted so two pictures differ.
     *
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function png(int $red = 255, int $green = 0, int $blue = 0, int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('GD cannot create an image', 1758614400);
        }
        $color = imagecolorallocate($image, $red, $green, $blue);
        imagefill($image, 0, 0, $color === false ? 0 : $color);
        ob_start();
        imagepng($image);

        return (string)ob_get_clean();
    }

    /**
     * A PNG of random pixels — like a photo, it does not compress to almost nothing, so a
     * scaled-down copy is smaller than the file. The same seed gives the same picture.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function photo(int $width, int $height, int $seed = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('GD cannot create an image', 1790000101);
        }
        mt_srand($seed);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        mt_srand();
        ob_start();
        imagepng($image);

        return (string)ob_get_clean();
    }
}
