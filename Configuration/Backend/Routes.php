<?php

declare(strict_types=1);

use Webconsulting\DocxEditor\Controller\Backend\EditorController;
use Webconsulting\DocxEditor\PageSync\Backend\PageSyncController;

/**
 * The docx editor is reached only via the "edit" action on docx files in the
 * file list (see AddDocxEditFileActionListener) — it is intentionally NOT a
 * backend module, so it does not appear in the module menu. Mirrors core's
 * `file_edit` route for the text-file editor.
 *
 * The page round trip is reached the same way, from the Page module's
 * DocHeader and the page tree's context menu (see PageModuleButtons and
 * PageSyncItemProvider).
 */
return [
    'docx_editor' => [
        'path' => '/docx-editor/edit',
        'target' => EditorController::class . '::editAction',
    ],
    PageSyncController::ROUTE_EDIT => [
        'path' => '/docx-editor/page',
        'target' => PageSyncController::class . '::editAction',
    ],
    PageSyncController::ROUTE_NEW_PAGES => [
        'path' => '/docx-editor/page/new',
        'target' => PageSyncController::class . '::newPagesAction',
    ],
    PageSyncController::ROUTE_DOWNLOAD => [
        'path' => '/docx-editor/page/download',
        'target' => PageSyncController::class . '::downloadAction',
    ],
];
