<?php

declare(strict_types=1);

use Webconsulting\DocxEditor\Controller\Backend\CollaborationApiController;
use Webconsulting\DocxEditor\Controller\Backend\DocumentApiController;

return [
    'docx_editor_document_load' => [
        'path' => '/docx-editor/document/load',
        'target' => DocumentApiController::class . '::loadAction',
        'methods' => ['GET'],
    ],
    'docx_editor_document_save' => [
        'path' => '/docx-editor/document/save',
        'target' => DocumentApiController::class . '::saveAction',
        'methods' => ['POST'],
    ],
    'docx_editor_document_save_as' => [
        'path' => '/docx-editor/document/save-as',
        'target' => DocumentApiController::class . '::saveAsAction',
        'methods' => ['POST'],
    ],
    'docx_editor_collab_join' => [
        'path' => '/docx-editor/collab/join',
        'target' => CollaborationApiController::class . '::joinAction',
        'methods' => ['POST'],
    ],
    'docx_editor_collab_heartbeat' => [
        'path' => '/docx-editor/collab/heartbeat',
        'target' => CollaborationApiController::class . '::heartbeatAction',
        'methods' => ['POST'],
    ],
    'docx_editor_collab_leave' => [
        'path' => '/docx-editor/collab/leave',
        'target' => CollaborationApiController::class . '::leaveAction',
        'methods' => ['POST'],
    ],
    'docx_editor_collab_presence' => [
        'path' => '/docx-editor/collab/presence',
        'target' => CollaborationApiController::class . '::presenceAction',
        'methods' => ['GET'],
    ],
    'docx_editor_collab_revision' => [
        'path' => '/docx-editor/collab/revision',
        'target' => CollaborationApiController::class . '::revisionAction',
        'methods' => ['GET'],
    ],
];
