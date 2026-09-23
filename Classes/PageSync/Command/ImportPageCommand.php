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
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\PageSplit;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;

/**
 * Imports a Word document into a page (--pid) or as new pages (--parent). Without --apply it
 * only prints what the import would do.
 */
#[AsCommand(name: 'docx-editor:page:import', description: 'Import a Word document into a page, or as new pages. Prints the plan only, unless --apply is given.')]
final class ImportPageCommand extends Command
{
    public function __construct(
        private readonly PageSyncService $pageSync,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'The .docx file')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Uid of the page to update')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Create new pages under this page instead')
            ->addOption('split', null, InputOption::VALUE_REQUIRED, 'With --parent: "none" (one page), "h1" (a page per heading 1) or "pagebreak" (a page per page break)', 'none')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'With --pid: language id of the page translation', '0')
            ->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, 'Workspace id to write into', '0')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes (default: dry run)')
            ->addOption('confirm-deletions', null, InputOption::VALUE_NONE, 'Delete elements that were removed from the document')
            ->addOption('prefer', null, InputOption::VALUE_REQUIRED, 'Who wins a conflict: "typo3" or "word"', PlanDecisions::TYPO3)
            ->addOption('no-jev', null, InputOption::VALUE_NONE, 'Choose content types by structure only, without asking Jev');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $labels = $this->languageServiceFactory->create('en');
        $file = $input->getArgument('file');
        $pid = self::integer($input->getOption('pid'));
        $parent = self::integer($input->getOption('parent'));
        if (!is_string($file) || !is_file($file) || !is_readable($file)) {
            $io->error('The file does not exist or is not readable.');

            return Command::INVALID;
        }
        if (($pid > 0) === ($parent > 0)) {
            $io->error('Give either --pid (update a page) or --parent (create new pages).');

            return Command::INVALID;
        }
        $split = PageSplit::tryFrom((string)self::text($input->getOption('split')));
        $prefer = self::text($input->getOption('prefer'));
        if ($split === null || !in_array($prefer, [PlanDecisions::TYPO3, PlanDecisions::WORD], true)) {
            $io->error('--split must be none, h1 or pagebreak; --prefer must be typo3 or word.');

            return Command::INVALID;
        }

        try {
            $user = CliBackendUser::initialize(self::integer($input->getOption('workspace')));
            $binary = (string)file_get_contents($file);
            $useJev = !(bool)$input->getOption('no-jev');
            $preview = $pid > 0
                ? $this->pageSync->preview($binary, $pid, self::integer($input->getOption('language')), $user, $useJev)
                : $this->pageSync->previewNewPages($binary, $parent, $split, $user, $useJev);

            $printer = new PlanPrinter($io, $labels);
            foreach ($preview->plans as $plan) {
                $printer->plan($plan);
            }
            if (!(bool)$input->getOption('apply')) {
                $this->pageSync->discard($preview->id);
                $io->note('Dry run: nothing was written. Run again with --apply to import.');

                return Command::SUCCESS;
            }

            $deletions = [];
            if ((bool)$input->getOption('confirm-deletions')) {
                foreach ($preview->plans as $plan) {
                    $deletions = [...$deletions, ...self::deletions($plan)];
                }
            }
            $results = $this->pageSync->apply($preview->id, new PlanDecisions(
                confirmedDeletions: $deletions,
                conflictDefault: $prefer,
            ), $user);
        } catch (PageSyncException $exception) {
            $io->error($exception->localizedMessage($labels));

            return Command::FAILURE;
        }

        $failed = false;
        foreach ($results as $result) {
            $printer->result($result);
            $failed = $failed || !$result->succeeded();
        }
        if ($failed) {
            return Command::FAILURE;
        }
        $io->success('The document was imported.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function deletions(SyncPlan $plan): array
    {
        $ids = [];
        $walk = static function (PlanEntry $entry) use (&$ids, &$walk): void {
            if ($entry->action === EntryAction::Delete) {
                $ids[] = $entry->id;
            }
            foreach ($entry->children as $child) {
                $walk($child);
            }
        };
        foreach ($plan->entries as $entry) {
            $walk($entry);
        }

        return $ids;
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
