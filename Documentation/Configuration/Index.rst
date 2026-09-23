..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

There is nothing to configure: no TypoScript, no TSconfig, no extension
settings. Access follows FAL permissions.

Routes
======

..  list-table::
    :header-rows: 1
    :widths: 40 60

    * - Route
      - Purpose
    * - ``docx_editor`` (:file:`/docx-editor/edit`)
      - The editor page. ``file`` (or ``target``, as sent by the file list)
        carries the combined identifier, e.g. ``1:/user_upload/report.docx``.
    * - ``docx_editor_document_load`` / ``_save`` / ``_save_as``
      - Load the binary (base64 JSON), save with revision check, store a copy
        in another folder.
    * - ``docx_editor_collab_join`` / ``_heartbeat`` / ``_leave`` /
        ``_presence`` / ``_revision``
      - Presence session and revision polling.

All AJAX responses share the envelope ``{"ok": true, …}`` or
``{"ok": false, "error": "…"}`` with a matching HTTP status (400, 403, 404,
409, 415); the error is translated into the backend user's language.

The editor route accepts a ``returnUrl`` like core's text file editor; without
one, :guilabel:`Close` returns to the file's folder in the file list.

..  _security:

Security
========

-   Every route requires an authenticated backend session; FAL storage and
    file permissions are checked before reading or writing.
-   Saves with an outdated revision return HTTP 409 instead of overwriting.
-   "Save as" content must pass TYPO3's resource consistency check (mime type
    vs. ``.docx``); other content is rejected with HTTP 415.
-   The presence table stores backend user id, display name and heartbeat
    only; the revision table stores counters and SHA-256 hashes.

Content-Security-Policy
-----------------------

The backend policy stays as TYPO3 ships it on every route but one: on the
editor route (``docx_editor``), the PSR-14 listener
:php:`AllowEditorEngineInContentSecurityPolicy` adds

-   ``script-src 'wasm-unsafe-eval'`` — the text shaper (HarfBuzz) is
    WebAssembly, loaded from :file:`Resources/Public/Vite/assets/`. The
    keyword allows compiling WebAssembly; JavaScript ``eval`` stays forbidden,
    and the Vue templates are compiled at build time, so no ``'unsafe-eval'``
    is needed;
-   ``img-src blob:`` — the engine paints the document's images from
    ``blob:`` URLs it creates from the package.

Fonts and the WebAssembly module are fetched from the extension's public
folder (same origin); the editor contacts no CDN and no font service.
:file:`Tests/Functional/ContentSecurityPolicyTest.php` checks both the
editor route and that other backend routes keep the core policy.
