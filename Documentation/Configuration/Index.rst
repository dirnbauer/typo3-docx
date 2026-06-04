..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension works without TypoScript or TSconfig. Backend routes and AJAX
endpoints are registered in:

- :file:`Configuration/Backend/Modules.php`
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

Module route
============

The editor module identifier is ``docx_editor`` (parent: ``media``). Open a
file with the ``file`` query parameter containing the combined FAL identifier,
for example ``1:/user_upload/example.docx``.
