<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\Service\RevisionService;

final class RevisionServiceTest extends UnitTestCase
{
    #[Test]
    public function computeContentHashIsAStableSha256(): void
    {
        $service = new RevisionService(self::createStub(ConnectionPool::class));
        $hash = $service->computeContentHash('sample-docx-binary');

        self::assertSame(hash('sha256', 'sample-docx-binary'), $hash);
        self::assertSame($hash, $service->computeContentHash('sample-docx-binary'));
        self::assertNotSame($hash, $service->computeContentHash('sample-docx-binary-changed'));
    }
}
