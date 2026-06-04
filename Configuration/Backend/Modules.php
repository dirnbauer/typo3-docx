<?php

declare(strict_types=1);

use Webconsulting\DocxEditor\Controller\Backend\EditorController;

return [
    'docx_editor' => [
        'parent' => 'media',
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/media/docx',
        'iconIdentifier' => 'docx-editor-module',
        'labels' => 'LLL:EXT:docx_editor/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => EditorController::class . '::editAction',
            ],
            'edit' => [
                'target' => EditorController::class . '::editAction',
            ],
        ],
        'moduleData' => [
            'file' => [
                'type' => 'input',
            ],
        ],
    ],
];
