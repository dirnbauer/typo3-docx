..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

What it does
============

|extension_name| integrates the open-source
`eigenpal/docx-editor <https://github.com/eigenpal/docx-editor>`_ library into
TYPO3. Editors work on Word documents stored in FAL without leaving the
backend.

Key features
============

- Primary **Edit DOCX** action in the Media file list
- Full-page backend editor with TYPO3 docheader (save, save as, FAL breadcrumbs)
- Compact formatting toolbar with H1–H4 shortcuts and TYPO3 light/dark tokens
- Active editor presence (who is online)
- Revision tracking and conflict-safe saves
- English and German backend labels (XLIFF 2.0, ICU)

Requirements
============

- TYPO3 14.3 LTS or newer
- PHP 8.2 or newer
- Composer installation (classic mode)

Collaboration model
===================

The extension ships **presence** and **revision polling**. When another user
saves, you are prompted to reload. Full real-time CRDT merging (for example
Yjs) is not bundled; the AJAX API is stable enough to add a dedicated sync
service later if needed.
