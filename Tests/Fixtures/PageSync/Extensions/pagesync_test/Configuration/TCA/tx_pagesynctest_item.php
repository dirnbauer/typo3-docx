<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Accordion item',
        'label' => 'title',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'versioningWS' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'translationSource' => 'l10n_source',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns' => ['disabled' => 'hidden'],
        'hideTable' => true,
        // As Content Blocks does for collection tables: records live on standard pages.
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'columns' => [
        'foreign_table_parent_uid' => ['config' => ['type' => 'passthrough']],
        'tablenames' => ['config' => ['type' => 'passthrough']],
        'fieldname' => ['config' => ['type' => 'passthrough']],
        'title' => [
            'label' => 'Title',
            'exclude' => true,
            'config' => ['type' => 'text', 'rows' => 2, 'required' => true],
        ],
        'content' => [
            'label' => 'Content',
            'exclude' => true,
            'config' => ['type' => 'text', 'enableRichtext' => true],
        ],
        'image' => [
            'label' => 'Image',
            'exclude' => true,
            'config' => ['type' => 'file', 'maxitems' => 1, 'allowed' => 'common-image-types'],
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'title, content, image'],
    ],
];
