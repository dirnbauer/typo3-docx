<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Export;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Export\ExportState;
use Webconsulting\DocxEditor\PageSync\Manifest\ControlTag;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestField;
use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;

final class ExportStateTest extends UnitTestCase
{
    /**
     * Content Blocks names can make "typo3:table:uid:field" longer than Word's 64 characters;
     * such controls get "typo3:#record:field", which only the manifest can resolve — also for
     * collections and read-only fields, which have no hash of their own.
     */
    #[Test]
    public function referencedFieldsWithoutAHashStayResolvable(): void
    {
        $state = new ExportState();
        $field = 'desiderio_comparisontable_feature_row_items_long';
        $record = $state->referenceFor('tt_content', 83978);
        $collection = $state->fieldReferenceFor('tt_content', 83978, $field);
        $header = $state->fieldReferenceFor('tt_content', 83978, 'header');
        $state->record('tt_content', 83978, ['header' => new ManifestField('header', 'abc', 2)], 'desiderio_comparisontable');
        $manifest = new RoundTripManifest(1, 'main', 0, 0, new \DateTimeImmutable(), $state->manifestRecords());

        self::assertEquals(ControlTag::field('tt_content', 83978, $field), $manifest->resolve(ControlTag::reference($record, $collection)));
        self::assertEquals(ControlTag::field('tt_content', 83978, 'header'), $manifest->resolve(ControlTag::reference($record, $header)));
        self::assertSame('', $manifest->records['tt_content:83978']->field($field)?->hash, 'No base to compare a collection with');
        self::assertSame('abc', $manifest->records['tt_content:83978']->field('header')?->hash);
    }
}
