<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Field\FieldCodec;
use Webconsulting\DocxEditor\PageSync\Html\BlocksToHtml;
use Webconsulting\DocxEditor\PageSync\Html\HtmlToBlocks;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestXml;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentReader;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentWriter;
use Webconsulting\DocxEditor\PageSync\Ooxml\WriteRequest;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;

/**
 * An untouched field must come back from Word unchanged. These are shapes found on real pages
 * where Word's structure differs from the HTML's; the comparison has to see through them.
 */
final class FieldCodecTest extends UnitTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function richTextWordRestructures(): array
    {
        return [
            'two quotes in a row' => ['<blockquote><p>First.</p></blockquote><blockquote><p>Second.</p></blockquote>'],
            'attribution after a quote' => ['<blockquote><p>Words.</p></blockquote><p>— Jane Doe</p>'],
            'paragraph entirely in code' => ['<p><code>composer require webconsulting/docx-editor</code></p>'],
            'two code blocks in a row' => ['<pre><code>one</code></pre><pre><code>two</code></pre>'],
            'a link to "#"' => ['<p>It can hold <a href="#">links</a>.</p>'],
            'characters sharing bytes with a no-break space' => ['<p>© 2026 webconsulting, voilà</p>'],
        ];
    }

    #[Test]
    #[DataProvider('richTextWordRestructures')]
    public function anUntouchedRichTextFieldComesBackUnchanged(string $html): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key-for-the-page-sync-manifest';
        $codec = new FieldCodec(new HtmlToBlocks(), new BlocksToHtml());
        $field = new FieldInfo('tt_content', 'bodytext', 'Text', FieldKind::RichText, FieldRole::Body);
        $record = ['bodytext' => $html];

        $exported = $codec->export($field, $record);
        $manifestXml = new ManifestXml(new HashService());
        $binary = new DocumentWriter($manifestXml)->write(new WriteRequest($exported->blocks));
        $read = new DocumentReader($manifestXml)->read($binary)->blocks;

        self::assertSame($codec->canonicalOfRecord($field, $record), $codec->canonical($field, $read));
    }
}
