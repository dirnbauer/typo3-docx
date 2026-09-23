..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

|extension_name| embeds the open-source editor of
`docx-editor.dev <https://www.docx-editor.dev/>`_ (``@docx-editor.dev/core``
and ``@docx-editor.dev/vue``, Apache-2.0) into the TYPO3 backend so editors can
work on Word documents stored in FAL without leaving TYPO3.

Features
========

-   **Edit DOCX** action on `.docx` files in the file list
-   Full-page editor with the DocHeader of core's text file editor: Close
    (with the core "unsaved changes" dialog), Save with *Save and close* and
    *Save as…*, Download, FAL breadcrumb, :kbd:`Ctrl/Cmd+S`
-   Word-faithful layout: text shaped with HarfBuzz, pages, headers and
    footers, notes, tables, images, fields, rulers and a navigation pane;
    metric-compatible open fonts load same-origin when a document uses them
-   Lossless saves: the editor writes back the package it opened, so content
    controls, bookmarks, custom XML, tracked changes, comments and everything
    it does not model survive
-   Style picker curated to Normal + Heading 1–4, H1–H4 toolbar buttons
-   Light and dark mode through TYPO3's design tokens; the document page stays
    white
-   Presence (who is online) and revision-safe saves (HTTP 409 on conflict)
-   English and German labels, editor chrome and error messages
-   A reusable ``<webcon-docx-editor>`` element with a byte-level API for other
    backend features, see :ref:`developer-integration`

Not included
============

Only the free, Apache-2.0 packages of docx-editor.dev are used. Suggesting
mode, tracked-change review (accept/reject, previous/next, markup views),
comments, reviewers, real-time collaboration and custom nodes belong to the
commercial ``@docx-editor.dev/pro`` package (EigenPal Pro licence), document
automation to ``@docx-editor.dev/editor-api`` and PDF conversion to
``@docx-editor.dev/docx-to-pdf``. None of them is installed, and the editor
shows none of their controls — no disabled entries, no upgrade hints — to any
user.

Tracked changes already in a document are shown in their final state
(insertions as text, deletions left out), as the open-source engine renders
them; comments are not shown. Both are kept on save. Printing is included
(:ref:`usage-print`); a PDF comes from the browser's print dialog
(:guilabel:`Save as PDF`). Presence (who is online) is the extension's own.

Licences
========

The extension is GPL-2.0-or-later. The editor bundle in
:file:`Resources/Public/Vite/` contains ``@docx-editor.dev/core``, ``/vue``,
``/i18n`` and ``/fonts`` (Apache-2.0), Vue (MIT) and their dependencies (MIT,
Apache-2.0, MIT AND Zlib); the fonts are under the SIL Open Font License
1.1 and the GUST Font License, HarfBuzz under its MIT-style licence. All
licence texts ship in :file:`Resources/Public/Vite/licenses/`.

Collaboration model
===================

The extension tracks *presence* and a per-file *revision counter*. It does not
merge concurrent edits: when another user saved first, the next save is
rejected and the page offers to reload.

Requirements
============

-   TYPO3 14.3 LTS (``typo3/cms-core ^14.3.7``), Composer mode
-   PHP 8.4 or 8.5
-   A current browser with WebAssembly (Chrome, Edge, Firefox, Safari)
