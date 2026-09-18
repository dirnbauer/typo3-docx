<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\Controller\Backend;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Webconsulting\DocxEditor\Controller\Backend\DocumentApiController;
use Webconsulting\DocxEditor\Tests\Functional\AbstractBackendRouteTestCase;

final class DocumentApiControllerTest extends AbstractBackendRouteTestCase
{
    #[Test]
    public function loadReturnsTheDocumentAsBase64WithRevisionZero(): void
    {
        $response = $this->load(self::DOCX);
        $data = self::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['ok']);
        self::assertSame(self::DOCX, $data['file']);
        self::assertSame('example.docx', $data['name']);
        self::assertSame(0, $data['revision']);
        self::assertSame('', $data['contentHash']);
        self::assertSame(file_get_contents(__DIR__ . '/../../Fixtures/Files/example.docx'), base64_decode((string)$data['data'], true));
    }

    #[Test]
    public function loadRefusesFilesThatAreNotDocx(): void
    {
        $response = $this->load(self::TXT);

        self::assertSame(415, $response->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'Only .docx files can be edited.'], self::json($response));
    }

    #[Test]
    public function saveWritesTheBinaryAndIncrementsTheRevision(): void
    {
        $binary = 'PK-new-content';
        $response = $this->save(['file' => self::DOCX, 'revision' => 0, 'data' => base64_encode($binary)]);
        $data = self::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $data['revision']);
        self::assertSame(hash('sha256', $binary), $data['contentHash']);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/user_upload/example.docx', $binary);

        $reloaded = self::json($this->load(self::DOCX));
        self::assertSame(1, $reloaded['revision']);
        self::assertSame($binary, base64_decode((string)$reloaded['data'], true));
    }

    #[Test]
    public function saveRejectsAStaleRevisionWithConflict(): void
    {
        $this->save(['file' => self::DOCX, 'revision' => 0, 'data' => base64_encode('first')]);
        $response = $this->save(['file' => self::DOCX, 'revision' => 0, 'data' => base64_encode('second')]);

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse(self::json($response)['ok']);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/user_upload/example.docx', 'first');
    }

    #[Test]
    public function saveWithoutRevisionSkipsTheConflictCheck(): void
    {
        $this->save(['file' => self::DOCX, 'revision' => 0, 'data' => base64_encode('first')]);
        $response = $this->save(['file' => self::DOCX, 'data' => base64_encode('second')]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, self::json($response)['revision']);
    }

    #[Test]
    public function saveRejectsMissingAndInvalidPayloads(): void
    {
        self::assertSame(400, $this->save(['file' => self::DOCX])->getStatusCode());
        self::assertSame(400, $this->save(['file' => self::DOCX, 'data' => '***'])->getStatusCode());
        self::assertSame(400, $this->save(['data' => base64_encode('x')])->getStatusCode());
    }

    #[Test]
    public function saveAsCreatesANewDocxAndSuffixesDuplicateNames(): void
    {
        $binary = (string)file_get_contents(__DIR__ . '/../../Fixtures/Files/example.docx');
        $body = ['folder' => '1:/user_upload/', 'fileName' => 'Copy', 'data' => base64_encode($binary)];

        $first = self::json($this->saveAs($body));
        self::assertSame('1:/user_upload/Copy.docx', $first['file']);
        self::assertSame('Copy.docx', $first['name']);
        self::assertSame(1, $first['revision']);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/user_upload/Copy.docx', $binary);

        $second = self::json($this->saveAs($body));
        self::assertSame('1:/user_upload/Copy_01.docx', $second['file']);
        self::assertSame(1, $second['revision']);
    }

    #[Test]
    public function saveAsRejectsContentThatIsNotAWordDocument(): void
    {
        $response = $this->saveAs(['folder' => '1:/user_upload/', 'fileName' => 'Bogus', 'data' => base64_encode('not a zip')]);

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('The content is not a valid .docx document.', self::json($response)['error']);
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/Bogus.docx');
    }

    #[Test]
    public function saveAsRequiresAFolder(): void
    {
        $response = $this->saveAs(['fileName' => 'Copy', 'data' => base64_encode('x')]);

        self::assertSame(400, $response->getStatusCode());
    }

    private function load(string $fileIdentifier): ResponseInterface
    {
        return $this->get(DocumentApiController::class)->loadAction(
            $this->ajaxRequest(1, 'docx_editor_document_load', ['file' => $fileIdentifier]),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function save(array $body): ResponseInterface
    {
        return $this->get(DocumentApiController::class)->saveAction(
            $this->ajaxRequest(1, 'docx_editor_document_save', [], $body),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function saveAs(array $body): ResponseInterface
    {
        return $this->get(DocumentApiController::class)->saveAsAction(
            $this->ajaxRequest(1, 'docx_editor_document_save_as', [], $body),
        );
    }
}
