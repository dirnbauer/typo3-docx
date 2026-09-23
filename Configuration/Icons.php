<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    // Core ships no printer icon; this one follows its 16 × 16 action icons.
    'docx-editor-print' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:docx_editor/Resources/Public/Icons/actions-print.svg',
    ],
];
