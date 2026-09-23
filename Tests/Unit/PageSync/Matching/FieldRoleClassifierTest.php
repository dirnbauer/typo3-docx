<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Matching;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRoleClassifier;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\LabShapes;

final class FieldRoleClassifierTest extends UnitTestCase
{
    /**
     * Field names from the lab's core, desiderio and astryx elements.
     *
     * @return array<string, array{0: string, 1: string, 2: FieldKind, 3: FieldRole}>
     */
    public static function fields(): array
    {
        return [
            'core header' => ['text', 'header', FieldKind::Input, FieldRole::Heading],
            'core subheader' => ['text', 'subheader', FieldKind::Input, FieldRole::Subheading],
            'core bodytext' => ['text', 'bodytext', FieldKind::RichText, FieldRole::Body],
            'table bodytext' => ['table', 'bodytext', FieldKind::Text, FieldRole::TableData],
            'bullets bodytext' => ['bullets', 'bodytext', FieldKind::Text, FieldRole::BulletData],
            'table caption' => ['table', 'table_caption', FieldKind::Input, FieldRole::Label],
            'uploads media' => ['uploads', 'media', FieldKind::File, FieldRole::Media],
            'assets' => ['textmedia', 'assets', FieldKind::File, FieldRole::Image],
            'card image' => ['desiderio_card', 'card_image', FieldKind::File, FieldRole::Image],
            'quote text' => ['desiderio_quote', 'quote_text', FieldKind::Text, FieldRole::Quote],
            'author' => ['desiderio_quote', 'author', FieldKind::Text, FieldRole::Attribution],
            'role' => ['desiderio_quote', 'role', FieldKind::Text, FieldRole::Position],
            'eyebrow' => ['desiderio_stats', 'eyebrow', FieldKind::Text, FieldRole::Subheading],
            'subheadline' => ['desiderio_featuregrid3', 'subheadline', FieldKind::Text, FieldRole::Subheading],
            'button text' => ['desiderio_herominimal', 'button_text', FieldKind::Text, FieldRole::LinkLabel],
            'theme link label' => ['text', 'tx_themecamino_link_label', FieldKind::Input, FieldRole::LinkLabel],
            'badge text' => ['desiderio_card', 'badge_text', FieldKind::Text, FieldRole::Label],
            'stat value' => ['', 'value', FieldKind::Text, FieldRole::Value],
            'question' => ['', 'question', FieldKind::Text, FieldRole::Heading],
            'answer' => ['', 'answer', FieldKind::RichText, FieldRole::Body],
            'camel case' => ['', 'buttonLabel', FieldKind::Input, FieldRole::LinkLabel],
            'video' => ['', 'background_video', FieldKind::File, FieldRole::Image],
            'download' => ['', 'download_file', FieldKind::File, FieldRole::Media],
            'code' => ['', 'code', FieldKind::Text, FieldRole::Code],
            'unknown rich text' => ['', 'xyz', FieldKind::RichText, FieldRole::Body],
            'unknown line' => ['', 'xyz', FieldKind::Input, FieldRole::Label],
            'link' => ['', 'button_link', FieldKind::Link, FieldRole::Link],
        ];
    }

    #[Test]
    #[DataProvider('fields')]
    public function rolesFollowTheFieldName(string $type, string $name, FieldKind $kind, FieldRole $expected): void
    {
        self::assertSame($expected, new FieldRoleClassifier()->classify('tt_content', $type, $name, '', $kind));
    }

    #[Test]
    public function theLabelDecidesWhenTheNameSaysNothing(): void
    {
        self::assertSame(FieldRole::Quote, new FieldRoleClassifier()->classify('x', '', 'field_a1', 'Testimonial', FieldKind::Text));
    }

    #[Test]
    public function nameIsTheQuotedPersonInAQuoteAndASecondHeadingIsASubheading(): void
    {
        $quote = LabShapes::shape('desiderio_quote');
        self::assertSame(FieldRole::Heading, $quote->field('header')?->role);
        self::assertSame(FieldRole::Attribution, $quote->field('author')?->role);

        $gallery = LabShapes::shape('desiderio_gallery');
        $child = $gallery->children['desiderio_gallery_items'] ?? null;
        self::assertNotNull($child);
        self::assertSame(FieldRole::Heading, $child->field('title')?->role);
        self::assertSame(FieldRole::Body, $child->field('description')?->role);
        self::assertSame(FieldRole::Image, $child->field('image')?->role);
    }
}
