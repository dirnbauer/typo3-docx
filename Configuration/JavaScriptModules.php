<?php

declare(strict_types=1);

return [
    'dependencies' => ['backend'],
    'imports' => [
        '@webconsulting/docx-editor/' => 'EXT:docx_editor/Resources/Public/JavaScript/',
        '@webconsulting/docx-editor/editor.js' => 'EXT:docx_editor/Resources/Public/Vite/docx-editor.js',
    ],
];
