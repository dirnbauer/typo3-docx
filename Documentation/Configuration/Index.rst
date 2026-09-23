..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The file editor needs no configuration: no TypoScript, no TSconfig. Access
follows FAL permissions. The page round trip (:ref:`page-sync`) has a few
extension settings.

..  _page-sync-settings:

Extension settings
==================

:guilabel:`Admin Tools > Settings > Extension Configuration > docx_editor`,
tab :guilabel:`pageSync`:

..  list-table::
    :header-rows: 1
    :widths: 30 20 50

    * - Setting
      - Default
      - Purpose
    * - ``pageSync.imageFolder``
      - ``1:/user_upload/word/{page}/``
      - FAL folder for pictures from Word documents; ``{page}`` is the page
        uid. Identical pictures are stored once.
    * - ``pageSync.newPagesHidden``
      - ``1``
      - Pages created from a Word document start hidden.
    * - ``pageSync.maxUploadMegabytes``
      - ``25``
      - Larger documents are refused, as are packages that unpack to far more
        than their size.
    * - ``pageSync.wordTemplate``
      - (empty)
      - An ``EXT:`` or project path to a ``.dotx``/``.docx`` whose styles
        exported documents use.
    * - ``pageSync.excludedContentTypes``
      - ``html``
      - CTypes never proposed for new content.
    * - ``pageSync.jevEnabled``
      - ``1``
      - Ask Jev (webcon_jev) between content types that fit equally well.
    * - ``pageSync.jevConfidenceThreshold``
      - ``0.6``
      - Below it, Jev's answer is shown but the structural fit is kept.
    * - ``pageSync.jevMaxCandidates``
      - ``5``
      - How many of the best structural fits Jev chooses between.
    * - ``pageSync.jevCacheLifetime``
      - ``-1``
      - Seconds Jev's answer for the same part is reused; ``-1`` uses
        webcon_jev's setting.

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
    * - ``docx_editor_page`` (:file:`/docx-editor/page`)
      - :guilabel:`Edit in Word` for page ``id`` in ``language``.
    * - ``docx_editor_page_new`` / ``docx_editor_page_download``
      - Import a document as subpages of ``id``; download page ``id`` as a
        document.
    * - ``docx_editor_page_load`` / ``_preview`` / ``_apply`` / ``_discard``
      - The page as a document; what importing a document would change (the
        document is kept for its user for a day in
        :file:`var/transient/docx_editor/page-sync/`); apply a reviewed
        preview; forget it.

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
-   Word documents are read with DOCTYPE and entities refused and network
    access off, inside size limits for the archive, each part and the unpacked
    total (no zip bombs). Part names that could escape the package are
    refused.
-   An import is planned first and applied only from a stored preview that
    belongs to the same backend user and workspace, and only if TYPO3 still
    looks as it did in the preview. Everything goes through the DataHandler:
    record, field, language and workspace permissions, the RTE transformation
    and the HTML sanitizer apply as for any edit.
-   The manifest inside a document is signed (HMAC with the installation's
    encryption key). A document whose manifest was altered is still
    recognised, but its record of the exported values is not trusted, so
    every difference counts as a conflict.

Content-Security-Policy
-----------------------

The backend policy stays as TYPO3 ships it on every route but two: on the
editor route (``docx_editor``) and the page round trip's :guilabel:`Edit in
Word` route (``docx_editor_page``), the PSR-14 listener
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
