<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\DocxEditor\Service\RevisionService;

final class RevisionServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = ['webconsulting/docx-editor'];

    #[Test]
    public function unknownFilesStartAtRevisionZero(): void
    {
        $state = $this->get(RevisionService::class)->getRevisionState('1:/user_upload/unknown.docx');

        self::assertSame(['revision' => 0, 'contentHash' => '', 'savedBy' => 0, 'changedAt' => 0], $state);
    }

    #[Test]
    public function registerSaveIncrementsTheRevisionPerFile(): void
    {
        $service = $this->get(RevisionService::class);

        self::assertSame(1, $service->registerSave('1:/user_upload/a.docx', 'hash-a1', 1));
        self::assertSame(2, $service->registerSave('1:/user_upload/a.docx', 'hash-a2', 2));
        self::assertSame(1, $service->registerSave('1:/user_upload/b.docx', 'hash-b1', 1));

        $state = $service->getRevisionState('1:/user_upload/a.docx');
        self::assertSame(2, $state['revision']);
        self::assertSame('hash-a2', $state['contentHash']);
        self::assertSame(2, $state['savedBy']);
        self::assertGreaterThan(0, $state['changedAt']);
    }
}
