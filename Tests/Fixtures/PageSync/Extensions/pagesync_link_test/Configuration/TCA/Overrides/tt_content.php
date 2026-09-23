<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// A button the way Content Blocks registers one: a label and a link field.
ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'pagesynctest_link' => [
        'label' => 'Button link',
        'config' => ['type' => 'link'],
    ],
]);
ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Button',
    'value' => 'pagesynctest_button',
    'group' => 'default',
    'description' => 'A button that links somewhere',
]);
$GLOBALS['TCA']['tt_content']['types']['pagesynctest_button'] = ['showitem' => '--palette--;;general, header, pagesynctest_link'];
