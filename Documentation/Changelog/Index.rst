..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

The full history is kept in
`CHANGELOG.md <https://github.com/dirnbauer/typo3-docx/blob/main/CHANGELOG.md>`__.

2.2.0
=====

-   Print (:ref:`usage-print`): DocHeader button, :guilabel:`File > Print` and
    :kbd:`Ctrl/Cmd+P` in the file editor and in :guilabel:`Edit in Word`; one
    sheet per page at the document's paper size, PDF through the browser's
    print dialog. No commercial package, no new dependency, no CSP change.

2.1.0
=====

-   Edit pages in Word (:ref:`page-sync`): the Page module's Word menu and
    the page tree's context menu open a page as a Word document in the
    embedded editor, download it or import an edited one; every change is
    reviewed before it is written through the DataHandler.
-   Import Word documents as subpages, split into the content elements that
    fit them best; Jev (webcon_jev 0.2.1, optional) decides between equally
    good types.
-   Commands ``docx-editor:page:export`` and ``docx-editor:page:import``.

2.0.0
=====

-   New engine: docx-editor.dev 2.21 (``@docx-editor.dev/core``, ``/vue``,
    ``/i18n``, ``/fonts``; Apache-2.0) replaces ``@eigenpal/docx-editor`` 1.9
    and React. Word-faithful layout, lossless saves of the opened package,
    fonts served from the extension, German chrome completed.
-   Curated Normal + Heading 1–4 through the toolbar's own style picker, by
    Word style name; the chunk patches are gone.
-   ``'wasm-unsafe-eval'`` and ``blob:`` images on the editor route only.
-   ``<webcon-docx-editor>`` with a byte-level API, ``content-controls="show"``,
    ``load-url`` / ``save-url`` on ``<typo3-docx-editor>``.
-   Round-trip fidelity tests.
-   Not included any more: tracked-change review and comment threads
    (commercial ``@docx-editor.dev/pro`` upstream).

1.5.0
=====

-   Native TYPO3 v14 chrome: core Close (with the "unsaved changes" dialog)
    and Save buttons, *Save and close*, a file name modal for *Save as…*, a
    page heading, core badge and status indicator for presence, core callout
    for newer revisions, and an error page with a DocHeader.
-   Error messages and all script labels come from the ``docx_editor.messages``
    domain in the backend user's language; the ``data-labels`` JSON and the
    custom ICU formatter are gone.
-   Dark mode: eigenpal's tokens are mapped with relative colour syntax, and
    menus and popovers are themed; the document page stays white.
-   PHP 8.5 gates CI next to 8.4.

1.4.0
=====

-   Structural cleanup: one label file and translation domain, one
    ``data-labels`` JSON for all JavaScript labels, stable bundle file names
    (no Vite manifest, ``ViteAssetResolver`` removed), presence rows deleted
    instead of soft-deleted.
-   ``<typo3-docx-editor>`` is a vanilla custom element; Lit removed. Vite 8.
-   "Save as" content failing TYPO3's resource consistency check answers with a
    JSON 415 instead of an exception page.
-   Fixed: the "Save failed" and heading labels never resolved their
    translations.
-   Functional tests for the document and collaboration APIs.
