<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\DocxEditor\Service\RevisionService;

final class RevisionServiceTest extends TestCase
{
    public function testComputeContentHashIsStable(): void
    {
        $service = new RevisionService($this->createMock(ConnectionPool::class));
        $hash = $service->computeContentHash('sample-docx-binary');
        self::assertSame(64, strlen($hash));
        self::assertSame($hash, $service->computeContentHash('sample-docx-binary'));
    }
}
