<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Element types shaped the way Content Blocks registers them: own fields, a collection with a
// child table, and a plugin-like type without content fields.
ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'pagesynctest_items' => [
        'label' => 'Items',
        'exclude' => true,
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_pagesynctest_item',
            'foreign_field' => 'foreign_table_parent_uid',
            'foreign_table_field' => 'tablenames',
            'foreign_match_fields' => ['fieldname' => 'pagesynctest_items'],
            'foreign_sortby' => 'sorting',
            'appearance' => ['useSortable' => true],
        ],
    ],
    'pagesynctest_settings' => [
        'label' => 'Settings',
        'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']]],
    ],
    'quote_text' => [
        'label' => 'Quote',
        'exclude' => true,
        'config' => ['type' => 'text', 'required' => true],
    ],
    'author' => [
        'label' => 'Author',
        'exclude' => true,
        'config' => ['type' => 'input'],
    ],
]);

foreach ([
    ['pagesynctest_accordion', 'Accordion', 'Questions and answers that open one at a time', '--palette--;;general, header, pagesynctest_items'],
    ['pagesynctest_quote', 'Quote', 'A quotation with its author', '--palette--;;general, quote_text, author'],
    ['pagesynctest_plugin', 'Event list', 'Lists upcoming events (plugin)', '--palette--;;general, pagesynctest_settings'],
] as [$type, $label, $description, $showitem]) {
    ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
        'label' => $label,
        'value' => $type,
        'group' => 'default',
        'description' => $description,
    ]);
    $GLOBALS['TCA']['tt_content']['types'][$type] = ['showitem' => $showitem];
}
