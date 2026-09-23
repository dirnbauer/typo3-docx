..  include:: /Includes.rst.txt

..  _developer:

=========
Developer
=========

Architecture
============

..  list-table::
    :header-rows: 1
    :widths: 45 55

    * - Part
      - Role
    * - :file:`Classes/Controller/Backend/EditorController.php`
      - Renders the editor page and its DocHeader: the core Close button,
        the core Save split button with *Save and close* and *Save as…*,
        Download.
    * - :file:`Classes/Controller/Backend/DocumentApiController.php`
        and :file:`CollaborationApiController.php`
      - JSON AJAX endpoints (envelope in :file:`AbstractDocxApiController.php`).
    * - :file:`Classes/Service/DocxFileService.php`
      - FAL resolution and permission checks; the only place that touches
        FAL permissions.
    * - :file:`Classes/Service/RevisionService.php`,
        :file:`CollaborationSessionService.php`
      - Revision counter and presence sessions.
    * - :file:`Classes/EventListener/AddDocxEditFileActionListener.php`
      - Adds :guilabel:`Edit DOCX` to the file list.
    * - :file:`Build/Sources/typo3-docx-editor.js`
      - ``<typo3-docx-editor>`` custom element (vanilla, light DOM): mounts
        React, exposes ``save()`` / ``saveAsToFolder()``, presence heartbeat.
    * - :file:`Build/Sources/docx-editor-mount.jsx`
      - React adapter around ``@eigenpal/docx-editor-react``: load, save,
        revision polling, H1–H4 toolbar.
    * - :file:`Resources/Public/JavaScript/toolbar.js`, :file:`notify.js`
      - TYPO3 ES modules (import map ``@webconsulting/docx-editor/``):
        DocHeader buttons, the unsaved-changes dialog (core labels from
        ``~labels/backend.alt_doc``), folder browser, file name dialog,
        notifications.
    * - :file:`Resources/Public/Css/Editor.*.css`
      - TYPO3 token mapping and toolbar theming, registered after the bundle
        CSS so they win the cascade.

Every script imports its labels from the ``docx_editor.messages`` domain
(``import labels from '~labels/docx_editor.messages'``); the Vite bundle keeps
``~labels/`` external so the backend import map resolves it at runtime.
Plurals are ICU messages. :file:`Build/Sources/labels.test.js` (run by
``npm run test:build``) fails when a script asks for a key that is missing in
English or German — the label providers throw on unknown keys.

Server-side errors are :php:`DocxEditorException` instances whose message is
a key of the same domain; the error page and the JSON API translate it into
the backend user's language (:php:`DocxEditorException::localizedMessage()`).

Frontend build
==============

..  code-block:: bash

    npm ci
    npm run test:build   # every chunk patch must still find its anchor
    npm run build        # -> Resources/Public/Vite/docx-editor.{js,css}

The bundle has stable file names, so PHP needs no manifest; TYPO3 adds its own
cache-busting. Commit :file:`Resources/Public/Vite/` — the CI ``assets`` job
rebuilds and fails on ``git diff``.

Upstream chunk patches
----------------------

Three Vite plugins in :file:`Build/vite/plugins/` rewrite the minified
``@eigenpal/docx-editor-react`` dist by content pattern (any chunk file name):

..  list-table::
    :header-rows: 1

    * - Plugin
      - Purpose
    * - :file:`heading4-fallback.js`
      - Appends Heading 4 to the built-in fallback style array (upstream
        stops at Heading 3).
    * - :file:`style-dropdown-headings.js`
      - Replaces the dropdown's option source with a filter over the fallback
        array so every document offers exactly Normal + Heading 1–4.
    * - :file:`popover-align.js`
      - Opens the editing-mode picker rightward; :file:`toolbar.js` clamps any
        popover back into the viewport at runtime.

Each plugin lists known ``SHAPES`` (``needle`` string or identifier-agnostic
regular expression, ``sample``, ``transform``). After bumping the upstream
packages run ``npm run test:build``; if a shape no longer matches, **add** a new
entry instead of editing old ones, re-run, rebuild, and check in the backend
that the dropdown shows Normal + H1–H4 and headings apply. If upstream ships
Heading 4 natively (``styles.heading4`` appears in the dist), delete
``heading4-fallback`` and its test.

The upstream package line
-------------------------

``@eigenpal/docx-editor-*`` 1.9.0 is the last release of that line; every
version is deprecated on npm. eigenpal continues the editor as
``@docx-editor.dev/react`` and ``@docx-editor.dev/core`` 2.x (Apache-2.0; the
review and collaboration features are in the commercial
``@docx-editor.dev/pro``). 2.x is a new API rather than an update: a
composition model (``DocxEditor.Root``, ``Toolbar``, ``Viewport``) and hooks
such as ``useParagraphStyle()``, which would replace the three chunk patches,
and a layout engine that, for exact line breaks, shapes text with a HarfBuzz
WebAssembly module from font files the host supplies (the backend CSP then
needs ``'wasm-unsafe-eval'``). Moving to it is a port of
:file:`Build/Sources/` and the theme, not a version bump.

Quality gates
=============

..  code-block:: bash

    composer ci        # validate, lint, cgl, phpstan (level 8), unit, functional
    composer assets    # npm ci, test:build, build, git diff --exit-code

Functional tests use sqlite by default (:file:`Build/phpunit/FunctionalTests.xml`);
CI runs them against MariaDB 10.11. They request the real backend routes with
a fixture storage and cover the editor page, the document API (load, save,
409, save-as) and the collaboration API.
