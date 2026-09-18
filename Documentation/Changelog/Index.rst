..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

The full history is kept in
`CHANGELOG.md <https://github.com/dirnbauer/typo3-docx/blob/main/CHANGELOG.md>`__.

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
