<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Html\BlocksToHtml;
use Webconsulting\DocxEditor\PageSync\Html\HtmlToBlocks;

final class HtmlConversionTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalForms(): array
    {
        return [
            'paragraphs with whitespace between blocks' => [
                "<p>First  paragraph.</p>\n<p>Second\nline</p>",
                '<p>First paragraph.</p><p>Second line</p>',
            ],
            'nested formatting collapses into canonical order' => [
                '<p><em><strong>both</strong></em> <b>bold</b> <i>it</i></p>',
                '<p><strong><em>both</em></strong> <strong>bold</strong> <em>it</em></p>',
            ],
            'overlapping marks share their open elements' => [
                '<p><strong>a<em>b</em>c</strong></p>',
                '<p><strong>a<em>b</em>c</strong></p>',
            ],
            'classes, styles and spans are dropped' => [
                '<p class="lead" style="color:red"><span class="x">Text</span> <font color="red">here</font></p>',
                '<p>Text here</p>',
            ],
            'headings keep their level' => [
                '<h2>Title</h2><h3 class="x">Sub</h3>',
                '<h2>Title</h2><h3>Sub</h3>',
            ],
            'nested lists' => [
                '<ul><li>one<ul><li>one.a</li></ul></li><li>two</li></ul><ol><li>first</li></ol>',
                '<ul><li>one<ul><li>one.a</li></ul></li><li>two</li></ul><ol><li>first</li></ol>',
            ],
            'list item paragraphs join with a line break' => [
                '<ol><li><p>a</p><p>b</p></li></ol>',
                '<ol><li>a<br>b</li></ol>',
            ],
            'tables with header row and colspan' => [
                '<table class="contenttable"><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td colspan="2"><p>wide</p></td></tr></tbody></table>',
                '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td colspan="2">wide</td></tr></tbody></table>',
            ],
            'quotes with attribution' => [
                '<blockquote><p>Less is more.</p><p>— Mies</p></blockquote>',
                '<blockquote><p>Less is more.</p><p>— Mies</p></blockquote>',
            ],
            'code blocks keep their whitespace' => [
                "<pre><code class=\"language-php\">if (\$a) {\n    echo 'x &amp; y';\n}</code></pre>",
                "<pre><code>if (\$a) {\n    echo 'x &amp; y';\n}</code></pre>",
            ],
            'links, TYPO3 links and titles survive' => [
                '<p><a href="t3://page?uid=12#c3" title="Go">Page</a> and <a href="https://example.org/?a=1&amp;b=2">ext</a></p>',
                '<p><a href="t3://page?uid=12#c3" title="Go">Page</a> and <a href="https://example.org/?a=1&amp;b=2">ext</a></p>',
            ],
            'non-breaking spaces and entities' => [
                '<p>10&nbsp;km &lt;fast&gt; &amp; safe</p>',
                '<p>10&nbsp;km &lt;fast&gt; &amp; safe</p>',
            ],
            'loose inline content becomes a paragraph' => [
                'Plain text <strong>without</strong> paragraph',
                '<p>Plain text <strong>without</strong> paragraph</p>',
            ],
            'line breaks, but not trailing ones' => [
                '<p>a<br>b<br></p>',
                '<p>a<br>b</p>',
            ],
            'empty paragraphs vanish' => [
                '<p>&nbsp;</p><p>x</p><p></p>',
                '<p>x</p>',
            ],
            'horizontal rule' => [
                '<p>a</p><hr><p>b</p>',
                '<p>a</p><hr><p>b</p>',
            ],
            'sub and superscript and code' => [
                '<p>H<sub>2</sub>O, x<sup>2</sup>, <code>run()</code>, <s>old</s>, <u>under</u></p>',
                '<p>H<sub>2</sub>O, x<sup>2</sup>, <code>run()</code>, <s>old</s>, <u>under</u></p>',
            ],
            'scripts and pictures are not content' => [
                '<p>a<script>alert(1)</script><img src="x.png" alt="y">b</p>',
                '<p>ab</p>',
            ],
        ];
    }

    #[Test]
    #[DataProvider('canonicalForms')]
    public function htmlConvertsToItsCanonicalForm(string $html, string $expected): void
    {
        $canonical = new BlocksToHtml()->convert(new HtmlToBlocks()->convert($html));

        self::assertSame($expected, $canonical);
        // Canonical HTML is a fixed point: converting it again changes nothing.
        self::assertSame($canonical, new BlocksToHtml()->convert(new HtmlToBlocks()->convert($canonical)));
    }

    #[Test]
    public function blocksCarryTheStructureOfTheHtml(): void
    {
        $blocks = new HtmlToBlocks()->convert(
            '<h2>Heading</h2><p>Text with <a href="https://example.org">a link</a>.</p>'
            . '<ul><li>a<ol><li>b</li></ol></li></ul>'
            . '<table><tr><th>H</th></tr><tr><td>v</td></tr></table>'
            . '<blockquote><p>Quote</p><footer>Someone</footer></blockquote>'
            . '<pre>code</pre>',
        );

        self::assertCount(6, $blocks);
        self::assertInstanceOf(Heading::class, $blocks[0]);
        self::assertSame(2, $blocks[0]->level);
        self::assertInstanceOf(Paragraph::class, $blocks[1]);
        self::assertInstanceOf(Link::class, $blocks[1]->inlines[1]);
        self::assertSame('https://example.org', $blocks[1]->inlines[1]->href);
        self::assertInstanceOf(ListBlock::class, $blocks[2]);
        self::assertSame([0, 1], array_map(static fn($item): int => $item->level, $blocks[2]->items));
        self::assertSame([false, true], array_map(static fn($item): bool => $item->ordered, $blocks[2]->items));
        self::assertInstanceOf(Table::class, $blocks[3]);
        self::assertTrue($blocks[3]->hasHeaderRow());
        self::assertInstanceOf(Quote::class, $blocks[4]);
        $citation = $blocks[4]->citation[0] ?? null;
        self::assertInstanceOf(Text::class, $citation);
        self::assertSame('Someone', $citation->text);
        self::assertCount(1, $blocks[4]->paragraphs);
        self::assertInstanceOf(CodeBlock::class, $blocks[5]);
    }

    #[Test]
    public function analysisTellsContentLossFromFormattingLoss(): void
    {
        $converter = new HtmlToBlocks();

        $clean = $converter->analyse('<p>Just <strong>text</strong></p>');
        self::assertFalse($clean->losesContent());
        self::assertFalse($clean->losesFormatting());

        $styled = $converter->analyse('<p class="lead">Text <span style="color:red">red</span></p>');
        self::assertFalse($styled->losesContent());
        self::assertTrue($styled->losesFormatting());

        $withMedia = $converter->analyse('<p><img src="a.png" data-htmlarea-file-uid="3"></p><iframe src="x"></iframe><my-widget></my-widget>');
        self::assertTrue($withMedia->losesContent());
        self::assertSame(['img', 'iframe', 'my-widget'], $withMedia->unsupportedElements);
    }
}
