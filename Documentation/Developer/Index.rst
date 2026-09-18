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
      - Renders the editor page, docheader buttons and the ``data-labels``
        JSON every JavaScript part reads its translations from.
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
        docheader buttons, folder browser, notifications.
    * - :file:`Resources/Public/Css/Editor.*.css`
      - TYPO3 token mapping and toolbar theming, registered after the bundle
        CSS so they win the cascade.

Labels reach JavaScript as one JSON attribute (``#docx-editor-app[data-labels]``)
built in ``EditorController::buildLabelsJson()``. The presence badge uses an
ICU plural string resolved client-side by :file:`docx-icu-format.js`.

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

Quality gates
=============

..  code-block:: bash

    composer ci        # validate, lint, cgl, phpstan (level 8), unit, functional
    composer assets    # npm ci, test:build, build, git diff --exit-code

Functional tests use sqlite by default (:file:`Build/phpunit/FunctionalTests.xml`);
CI runs them against MariaDB 10.11. They request the real backend routes with
a fixture storage and cover the editor page, the document API (load, save,
409, save-as) and the collaboration API.
