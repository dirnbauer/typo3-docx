<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentReader;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

/**
 * A site with page 1 in English (0) and German (1), content elements of the core types and of
 * types shaped like Content Blocks elements (an accordion with child records, a quote, a plugin),
 * a workspace "Draft", an admin (1), an editor (2, German, limited rights) and a reader (3).
 */
abstract class AbstractPageSyncTestCase extends FunctionalTestCase
{
    protected const string FIXTURES = __DIR__ . '/../../Fixtures/PageSync';

    protected array $coreExtensionsToLoad = ['filelist', 'workspaces', 'rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'webconsulting/docx-editor',
        __DIR__ . '/../../Fixtures/PageSync/Extensions/pagesync_test',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'docx_editor' => [
                'pageSync' => [
                    'jevEnabled' => '0',
                ],
            ],
        ],
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(self::FIXTURES . '/Database/users.csv');
        $this->importCSVDataSet(self::FIXTURES . '/Database/page.csv');
        $this->writeSiteConfiguration();
        $this->provideOfficePicture();
    }

    protected function backendUser(int $uid, int $workspace = 0): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        if ($workspace > 0) {
            $user->setWorkspace($workspace);
        }
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return $user;
    }

    protected function read(string $binary): DocxDocument
    {
        return $this->get(DocumentReader::class)->read($binary);
    }

    /**
     * The first content control with the tag, searched depth-first.
     *
     * @param list<Block> $blocks
     */
    protected static function control(array $blocks, string $tag): ?ContentControl
    {
        foreach ($blocks as $block) {
            if (!$block instanceof ContentControl) {
                continue;
            }
            if ($block->tag === $tag) {
                return $block;
            }
            $found = self::control($block->blocks, $tag);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $table, int $uid): array
    {
        $query = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $row = $query->select('*')->from($table)
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, $table . ':' . $uid . ' does not exist');

        return $row;
    }

    private function writeSiteConfiguration(): void
    {
        $directory = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_GB', 'base' => '/', 'enabled' => true, 'navigationTitle' => 'English', 'flag' => 'gb'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_AT', 'base' => '/de/', 'enabled' => true, 'navigationTitle' => 'Deutsch', 'flag' => 'at', 'fallbackType' => 'strict'],
            ],
        ], 5));
    }

    /**
     * A real picture in fileadmin, referenced by the Text & Media element (tt_content 3).
     */
    private function provideOfficePicture(): void
    {
        $directory = $this->instancePath . '/fileadmin/pagesync';
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/office.png', DocxFixtureBuilder::png(40, 120, 200, 64, 32));
        $storage = $this->get(StorageRepository::class)->findByUid(1);
        self::assertNotNull($storage);
        $file = $storage->getFile('/pagesync/office.png');
        self::assertNotNull($file);

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 1,
            'uid_local' => $file->getUid(),
            'uid_foreign' => 3,
            'tablenames' => 'tt_content',
            'fieldname' => 'assets',
            'sorting_foreign' => 1,
            'alternative' => 'Our office in Vienna',
            'description' => 'The entrance',
        ]);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['assets' => 1], ['uid' => 3]);
    }
}
