<?php

declare(strict_types=1);

use Webconsulting\DocxEditor\Controller\Backend\EditorController;

/**
 * The docx editor is reached only via the "edit" action on docx files in the
 * file list (see AddDocxEditFileActionListener) — it is intentionally NOT a
 * backend module, so it does not appear in the module menu. Mirrors core's
 * `file_edit` route for the text-file editor.
 */
return [
    'docx_editor' => [
        'path' => '/docx-editor/edit',
        'target' => EditorController::class . '::editAction',
    ],
];
