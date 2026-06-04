<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'docx-editor-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:docx_editor/Resources/Public/Icons/Extension.svg',
    ],
    'docx-editor-action-edit' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:docx_editor/Resources/Public/Icons/ActionEdit.svg',
    ],
];
