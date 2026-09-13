..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension works without TypoScript or TSconfig. The editor route and its
AJAX endpoints are registered in:

- :file:`Configuration/Backend/Routes.php`
- :file:`Configuration/Backend/AjaxRoutes.php`

AJAX routes
===========

..  list-table:: Backend AJAX identifiers
    :header-rows: 1
    :widths: 32 68

    * - Route name
      - Purpose
    * - ``docx_editor_document_load``
      - Load `.docx` binary (base64) and revision metadata
    * - ``docx_editor_document_save``
      - Save `.docx` binary with optional revision check
    * - ``docx_editor_document_save_as``
      - Store the document as a new file in another FAL folder
    * - ``docx_editor_collab_join``
      - Join collaboration session (presence)
    * - ``docx_editor_collab_heartbeat``
      - Refresh session heartbeat
    * - ``docx_editor_collab_leave``
      - Leave session
    * - ``docx_editor_collab_presence``
      - List active editors
    * - ``docx_editor_collab_revision``
      - Poll revision state

Editor route
============

The editor is a backend **route**, not a module — it is reached through the
:guilabel:`Edit DOCX` action in the file list and deliberately does not appear
in the module menu (mirroring the core ``file_edit`` text-file editor).

The route identifier is ``docx_editor`` (path :file:`/docx-editor/edit`). Open a
file with the ``file`` query parameter containing the combined FAL identifier,
for example ``1:/user_upload/example.docx``; ``target`` is accepted as an alias
because that is what the file list passes.
