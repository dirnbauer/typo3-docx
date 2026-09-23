<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use Webconsulting\DocxEditor\PageSync\Apply\PlanApplier;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\FieldStatus;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

/**
 * Pictures travel as copies scaled by TYPO3's image processing, and come back as the file
 * references they were exported from — unless the editor replaced them in Word.
 *
 * The office picture of tt_content 3 is a photo-like 64 × 32 PNG; with pageSync.pictureMaxEdge = 16
 * the document holds a 16 × 8 copy.
 */
final class PictureRoundTripTest extends AbstractPageSyncTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance = array_replace_recursive($this->configurationToUseInTestInstance, [
            'EXTENSIONS' => ['docx_editor' => ['pageSync' => ['pictureMaxEdge' => '16']]],
            'GFX' => self::imageProcessor(),
        ]);
        parent::setUp();
        // A photo-like office picture: a scaled copy of a one-colour PNG would not be smaller.
        $file = $this->get(StorageRepository::class)->findByUid(1)?->getFile('/pagesync/office.png');
        self::assertInstanceOf(File::class, $file);
        $file->setContents(DocxFixtureBuilder::photo(64, 32));
    }

    #[Test]
    public function anExportedPictureIsAScaledCopyThatStandsForItsFileReference(): void
    {
        $exported = $this->export(1, 0, $this->backendUser(1));
        $image = $this->officePicture($exported);
        $reference = $this->references('tt_content', 3, 'assets')[0] ?? [];
        $file = $this->row('sys_file', (int)($reference['uid_local'] ?? 0));

        $size = getimagesizefromstring($image->data->bytes);
        self::assertIsArray($size);
        self::assertSame([16, 8, 'image/png'], [$size[0], $size[1], $size['mime']], 'Embedded as a 16 × 8 PNG');
        self::assertNotSame($file['sha1'], $image->data->sha1());
        self::assertSame(64 * 9525, $image->widthEmu, 'Shown at the size of the file, not of the copy');

        self::assertSame((int)$reference['uid'], $image->referenceUid);
        self::assertSame((int)$file['uid'], $image->fileUid);
        self::assertSame($file['sha1'], $image->fileSha1);
        $picture = $exported->manifest?->picture((int)$reference['uid']);
        self::assertNotNull($picture);
        self::assertSame($image->data->sha1(), $picture->embedded);
        self::assertSame($file['sha1'], $picture->sha1);
    }

    #[Test]
    public function anUnchangedScaledPictureIsPlannedAsUnchanged(): void
    {
        $user = $this->backendUser(1);
        $plan = $this->plan($this->export(1, 0, $user), 1, 0, $user);

        self::assertSame(EntryAction::Unchanged, self::entryFor($plan, 3)->action);
        self::assertFalse($plan->hasWrites());
    }

    #[Test]
    public function aPictureWhoseNameAWordProcessorDroppedIsRecognisedByItsBytes(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);
        $image = $this->officePicture($exported);
        $renamed = new DocumentEditor($exported)
            ->replace('typo3:tt_content:3:assets', [new Figure(
                new Image($image->data, $image->alternative, $image->title),
                [new Text('The entrance')],
            )])
            ->document();

        self::assertSame(EntryAction::Unchanged, self::entryFor($this->plan($renamed, 1, 0, $user), 3)->action);
    }

    #[Test]
    public function editingTheAltTextKeepsTheFileReferenceAndTheFile(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);
        $image = $this->officePicture($exported);
        $reference = $this->references('tt_content', 3, 'assets')[0] ?? [];
        $files = $this->rowCount('sys_file');

        $edited = new DocumentEditor($exported)
            ->replace('typo3:tt_content:3:assets', [new Figure(
                new Image($image->data, 'Our new office', $image->title, $image->widthEmu, $image->heightEmu, $image->fileUid, $image->referenceUid, $image->fileSha1),
                [new Text('The entrance')],
            )])
            ->document();
        $plan = $this->plan($edited, 1, 0, $user);

        $office = self::entryFor($plan, 3);
        self::assertSame(EntryAction::Update, $office->action);
        self::assertSame(['assets'], array_map(static fn($change): string => $change->field->name, $office->fields));
        self::assertSame(FieldStatus::Changed, $office->fields[0]->status);

        $result = $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(), $user);
        self::assertSame([], $result->errors);
        $after = $this->references('tt_content', 3, 'assets');
        self::assertCount(1, $after);
        self::assertSame((int)$reference['uid'], (int)$after[0]['uid'], 'The same file reference');
        self::assertSame((int)$reference['uid_local'], (int)$after[0]['uid_local'], 'The same file, not the scaled copy');
        self::assertSame('Our new office', $after[0]['alternative']);
        self::assertSame($files, $this->rowCount('sys_file'), 'No file was added');
    }

    #[Test]
    public function aPictureReplacedInWordIsAChangeAndBecomesANewFile(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);
        $reference = $this->references('tt_content', 3, 'assets')[0] ?? [];
        $photo = DocxFixtureBuilder::png(10, 180, 90, 48, 24);

        $edited = new DocumentEditor($exported)
            ->replace('typo3:tt_content:3:assets', [new Figure(new Image(new ImageData($photo, 'image/png', 'new-office.png'), 'Our office in Vienna'), [new Text('The entrance')])])
            ->document();
        $plan = $this->plan($edited, 1, 0, $user);

        $office = self::entryFor($plan, 3);
        self::assertSame(['assets'], array_map(static fn($change): string => $change->field->name, $office->fields));
        self::assertSame(FieldStatus::Changed, $office->fields[0]->status);

        $result = $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(), $user);
        self::assertSame([], $result->errors);
        $after = $this->references('tt_content', 3, 'assets');
        self::assertCount(1, $after);
        self::assertNotSame((int)$reference['uid'], (int)$after[0]['uid']);
        $file = $this->row('sys_file', (int)$after[0]['uid_local']);
        self::assertStringStartsWith('/user_upload/word/1/our-office-in-vienna-', (string)$file['identifier']);
        self::assertSame(sha1($photo), $file['sha1'], 'The picture as Word holds it');
        self::assertSame(1, (int)$this->row('sys_file_reference', (int)$reference['uid'])['deleted']);
    }

    /**
     * Word's "Change Picture" keeps the picture's name — and so its tag — but not its bytes.
     */
    #[Test]
    public function aPictureChangedUnderTheSameNameIsStillAReplacement(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);
        $image = $this->officePicture($exported);

        $edited = new DocumentEditor($exported)
            ->replace('typo3:tt_content:3:assets', [new Figure(
                new Image(new ImageData(DocxFixtureBuilder::png(10, 180, 90, 16, 8), 'image/png', 'changed.png'), $image->alternative, $image->title, 0, 0, $image->fileUid, $image->referenceUid, $image->fileSha1),
                [new Text('The entrance')],
            )])
            ->document();

        $office = self::entryFor($this->plan($edited, 1, 0, $user), 3);
        self::assertSame(['assets'], array_map(static fn($change): string => $change->field->name, $office->fields));
        self::assertSame(FieldStatus::Changed, $office->fields[0]->status);
    }

    #[Test]
    public function anExportedPictureUsedOnceMoreRefersToItsFileAgain(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);
        $image = $this->officePicture($exported);
        $reference = $this->references('tt_content', 3, 'assets')[0] ?? [];
        $files = $this->rowCount('sys_file');

        $edited = new DocumentEditor($exported)
            ->appendToDocument([
                new Heading(2, [new Text('Visit us')]),
                new Paragraph([new Text('The door is open.')]),
                new Figure(new Image($image->data, 'Our office again')),
            ])
            ->document();
        $plan = $this->plan($edited, 1, 0, $user);
        $created = array_values(array_filter($plan->entries, static fn(PlanEntry $entry): bool => $entry->action === EntryAction::Create));
        self::assertCount(1, $created);

        $result = $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(), $user);
        self::assertSame([], $result->errors);
        $new = $this->picturesOf($result->created[$created[0]->id])[0] ?? [];
        self::assertSame((int)$reference['uid_local'], (int)($new['uid_local'] ?? 0), 'The exported file, not its scaled copy');
        self::assertSame('Our office again', $new['alternative'] ?? null);
        self::assertSame($files, $this->rowCount('sys_file'));
        self::assertCount(1, $this->references('tt_content', 3, 'assets'), 'The original reference stays');
    }

    /**
     * A document exported by 2.2 or earlier holds the file itself and no picture list.
     */
    #[Test]
    public function aDocumentWithTheFullFileAndNoPictureListStillMatches(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);
        $image = $this->officePicture($exported);
        $original = (string)file_get_contents($this->instancePath . '/fileadmin/pagesync/office.png');
        $manifest = $exported->manifest;
        self::assertNotNull($manifest);

        $older = new DocumentEditor(new DocxDocument($exported->blocks, new RoundTripManifest(
            $manifest->pageUid,
            $manifest->siteIdentifier,
            $manifest->language,
            $manifest->workspace,
            $manifest->exportedAt,
            $manifest->records,
            exportedBy: $manifest->exportedBy,
        ), $exported->customProperties, $exported->title))
            ->replace('typo3:tt_content:3:assets', [new Figure(new Image(new ImageData($original, 'image/png', 'office.png'), $image->alternative, $image->title), [new Text('The entrance')])])
            ->document();

        self::assertSame(EntryAction::Unchanged, self::entryFor($this->plan($older, 1, 0, $user), 3)->action);
    }

    private function officePicture(DocxDocument $document): Image
    {
        $assets = self::control($document->blocks, 'typo3:tt_content:3:assets');
        $figure = $assets->blocks[0] ?? null;
        self::assertInstanceOf(Figure::class, $figure);

        return $figure->image;
    }

    /**
     * The file references of a content element, whatever field they are in.
     *
     * @return list<array<string, mixed>>
     */
    private function picturesOf(int $uid): array
    {
        $query = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $query->getRestrictions()->removeAll();

        return $query->select('*')->from('sys_file_reference')
            ->where(
                $query->expr()->eq('tablenames', $query->createNamedParameter('tt_content')),
                $query->expr()->eq('uid_foreign', $query->createNamedParameter($uid, Connection::PARAM_INT)),
                $query->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function rowCount(string $table): int
    {
        $query = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();

        return (int)$query->count('uid')->from($table)->executeQuery()->fetchOne();
    }

    /**
     * TYPO3's image processing needs ImageMagick or GraphicsMagick (CI installs ImageMagick).
     *
     * @return array<string, bool|string>
     */
    private static function imageProcessor(): array
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'] as $path) {
            if (is_executable($path . 'magick') || is_executable($path . 'convert')) {
                return ['processor' => 'ImageMagick', 'processor_path' => $path, 'processor_enabled' => true];
            }
            if (is_executable($path . 'gm')) {
                return ['processor' => 'GraphicsMagick', 'processor_path' => $path, 'processor_enabled' => true];
            }
        }
        self::fail('These tests need ImageMagick or GraphicsMagick in /usr/bin, /usr/local/bin or /opt/homebrew/bin.');
    }
}
