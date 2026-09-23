<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Bootstrap;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;

final class CommandTest extends AbstractPageSyncTestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
        $this->directory = $this->instancePath . '/typo3temp/var/tests/pagesync-cli';
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o775, true);
        }
    }

    #[Test]
    public function exportWritesTheDocumentToTheGivenDirectory(): void
    {
        $tester = $this->tester('docx-editor:page:export');

        $tester->execute(['page' => '1', '--out' => $this->directory]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $file = $this->directory . '/our-services-en-gb.docx';
        self::assertFileExists($file, $tester->getDisplay());
        $document = $this->read((string)file_get_contents($file));
        self::assertSame(1, $document->manifest?->pageUid);
        self::assertStringContainsString('7 content elements', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    #[Test]
    public function importOnlyPrintsThePlanUnlessToldToApply(): void
    {
        $file = $this->editedExport();
        $tester = $this->tester('docx-editor:page:import');

        $tester->execute(['file' => $file, '--pid' => '1', '--no-jev' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('tt_content:2', $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringNotContainsString('We host websites too.', (string)$this->row('tt_content', 2)['bodytext']);

        $tester->execute(['file' => $file, '--pid' => '1', '--no-jev' => true, '--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('We host websites too.', (string)$this->row('tt_content', 2)['bodytext']);
    }

    #[Test]
    public function importAsNewPageCreatesThePage(): void
    {
        $file = $this->editedExport();
        $tester = $this->tester('docx-editor:page:import');

        $tester->execute(['file' => $file, '--parent' => '3', '--no-jev' => true, '--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, (int)$this->row('pages', 4)['hidden']);
        self::assertSame(3, (int)$this->row('pages', 4)['pid']);
    }

    #[Test]
    public function importRefusesAmbiguousTargets(): void
    {
        $tester = $this->tester('docx-editor:page:import');

        $tester->execute(['file' => $this->editedExport(), '--pid' => '1', '--parent' => '1']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    private function tester(string $name): CommandTester
    {
        return new CommandTester($this->get(CommandRegistry::class)->get($name));
    }

    private function editedExport(): string
    {
        $user = $this->backendUser(1);
        $exported = $this->read($this->get(PageSyncService::class)->export(1, 0, $user)->binary);
        $file = $this->directory . '/edited.docx';
        file_put_contents($file, $this->binary(new DocumentEditor($exported)
            ->replace('typo3:tt_content:2:bodytext', [new Paragraph([new Text('We host websites too.')])])
            ->document()));
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);

        return $file;
    }
}
