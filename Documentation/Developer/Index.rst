..  include:: /Includes.rst.txt

..  _developer:

=========
Developer
=========

Architecture
============

- **PHP**: FAL services, AJAX controllers, ``ProcessFileListActionsEvent`` listener
- **Fluid 5**: Module templates, partials, translated labels
- **Lit**: ``typo3-docx-editor`` custom element (Vite bundle glue)
- **Vite bundle**: React adapter for ``@eigenpal/docx-editor-react``
- **TYPO3 ES modules**: ``docx-editor-toolbar.js``, ``docx-editor-notify.js``

Frontend sources and rebuild steps: :file:`Build/Sources/README.md`.

Frontend layout
===============

..  list-table::
    :header-rows: 1

    * - File
      - Loaded by
      - Role
    * - :file:`Build/Sources/docx-editor.js`
      - Vite entry
      - Fonts guard, eigenpal CSS, Lit element registration
    * - :file:`Build/Sources/typo3-docx-editor.js`
      - Vite bundle
      - Lit host; exposes ``save()`` / ``saveAsToFolder()``; collaboration polling
    * - :file:`Build/Sources/docx-editor-mount.jsx`
      - Vite bundle
      - React adapter around eigenpal ``DocxEditor``
    * - :file:`Build/Sources/use-typo3-docx-editor-options.jsx`
      - Vite bundle
      - TYPO3 i18n, H1–H4 toolbar, save API wiring
    * - :file:`Build/Sources/docx-labels.js`
      - Vite bundle
      - Reads ``data-heading-labels`` JSON from ``#docx-editor-app``
    * - :file:`Resources/Public/JavaScript/docx-editor-toolbar.js`
      - TYPO3 ``PageRenderer``
      - Docheader save/save-as, keyboard shortcut, element browser
    * - :file:`Resources/Public/JavaScript/docx-editor-notify.js`
      - TYPO3 import map (also imported by Vite bundle)
      - ``Notification.success`` / ``error`` for save feedback

Save notifications
==================

The Lit element calls ``notifyDocxEditorStatus()`` from
:file:`docx-editor-notify.js` directly. The Vite bundle keeps that import
**external** so TYPO3 resolves it at runtime via
:file:`Configuration/JavaScriptModules.php`.

Heading labels
==============

Translated H1–H4 labels are passed as a single JSON attribute
(``data-heading-labels``) from :file:`EditorController.php`. Legacy per-key
``data-label-heading*`` attributes are still parsed as a fallback in
:file:`docx-labels.js`.

Theme CSS
=========

TYPO3 backend tokens override eigenpal/Tailwind utilities:

- :file:`Resources/Public/Css/Editor.tokens.css` — design token mapping
- :file:`Resources/Public/Css/Editor.base.css` — module layout, menus, heading toolbar
- :file:`Resources/Public/Css/Editor.toolbar.css` — compact formatting bar (multi-line wrap)
- :file:`Resources/Public/Css/Editor.css` — imports the three partials

:file:`Editor.css` is registered **after** the Vite stylesheet so TYPO3 tokens
win in the cascade. CSS-only changes do not require ``npm run build``.

Heading 4 Vite patch
====================

``@eigenpal/docx-editor-react@1.2.1`` omits Heading 4 from its built-in
fallback style list. The plugin in
:file:`Build/vite/plugins/heading4-fallback.js` patches chunk
``chunk-SW2JOSQG`` at build time.

After upgrading the npm package:

#. Run ``npm run test:build`` — fails if the chunk name or needle changed.
#. Update the plugin constants if needed.
#. Remove the plugin when upstream ships Heading 4 natively.

Quality gates
=============

..  code-block:: bash
    :caption: Run the full CI suite locally

    composer install
    npm ci && npm run test:build && npm run build
    Build/Scripts/runTests.sh -s ci

Suites: ``unit``, ``phpstan``, ``composer``, ``assets``, ``ci``.

After changing :file:`Build/Sources/`, run ``npm run build`` and commit
:file:`Resources/Public/Vite/docx-editor.js``.

PHPStan
=======

..  code-block:: bash

    vendor/bin/phpstan analyse

Extension boundaries
====================

Keep FAL and permission logic in
:file:`Classes/Service/DocxFileService.php`. Do not embed document binaries in
collaboration tables — revisions store hashes and counters only.
