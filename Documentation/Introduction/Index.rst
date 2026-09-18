..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

|extension_name| embeds the open-source
`eigenpal/docx-editor <https://github.com/eigenpal/docx-editor>`_ into the
TYPO3 backend so editors can work on Word documents stored in FAL without
leaving TYPO3.

Features
========

-   **Edit DOCX** action on `.docx` files in the file list
-   Full-page editor with TYPO3 docheader: back, download, save, save as,
    FAL breadcrumbs, :kbd:`Ctrl/Cmd+S`
-   Style dropdown curated to Normal + Heading 1–4, H1–H4 toolbar shortcuts,
    TYPO3 light/dark design tokens
-   Presence (who is online) and revision-safe saves (HTTP 409 on conflict)
-   English and German labels (XLIFF 2, ICU plural for the presence badge)

Collaboration model
===================

The extension tracks *presence* and a per-file *revision counter*. It does not
merge concurrent edits: when another user saved first, the next save is
rejected and the page offers to reload.

Requirements
============

-   TYPO3 14.3 LTS (``typo3/cms-core ^14.3.7``), Composer mode
-   PHP 8.4
