<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;

#[AsCommand(name: 'docx-editor:page:export', description: 'Export the content of a page as a Word document that can be edited and imported again.')]
final class ExportPageCommand extends Command
{
    public function __construct(
        private readonly PageSyncService $pageSync,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly PageSyncSettings $settings,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('page', InputArgument::REQUIRED, 'Uid of the page')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Language id of the page translation to export', '0')
            ->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, 'Workspace id to export from', '0')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'File or directory to write to (default: the current directory; a path ending in / is a directory and is created)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $labels = $this->languageServiceFactory->create('en');
        try {
            $user = CliBackendUser::initialize(self::integer($input->getOption('workspace')));
            $result = $this->pageSync->export(self::integer($input->getArgument('page')), self::integer($input->getOption('language')), $user);
        } catch (PageSyncException $exception) {
            $io->error($exception->localizedMessage($labels));

            return Command::FAILURE;
        }

        $out = $input->getOption('out');
        $target = is_string($out) && $out !== '' ? $out : (string)getcwd();
        if (str_ends_with($target, '/') && !is_dir($target)) {
            GeneralUtility::mkdir_deep($target);
        }
        if (is_dir($target)) {
            $target = rtrim($target, '/') . '/' . $result->fileName;
        }
        if (file_put_contents($target, $result->binary) === false) {
            $io->error(sprintf('Could not write %s', $target));

            return Command::FAILURE;
        }
        $size = strlen($result->binary);
        $io->success(sprintf('%d content elements written to %s (%s MB)', $result->elementCount, $target, number_format($size / 1048576, 1)));
        if ($size > $this->settings->maxUploadMegabytes() * 1048576) {
            $io->warning(sprintf(
                'The document is larger than the %d MB an import accepts (pageSync.maxUploadMegabytes). Lower pageSync.pictureResolution or raise the limit to import it again.',
                $this->settings->maxUploadMegabytes(),
            ));
        }
        if ($result->skipped !== []) {
            $io->note(sprintf('Not part of the document (no column on the page, or inside a container): tt_content %s', implode(', ', $result->skipped)));
        }

        return Command::SUCCESS;
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
