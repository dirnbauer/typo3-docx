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

Upstream editor (``@eigenpal/docx-editor-react``)
=================================================

The WYSIWYG core is the npm package ``@eigenpal/docx-editor-react`` (plus
``-core`` and ``-i18n``), bundled by Vite into
:file:`Resources/Public/Vite/docx-editor.js`. Two build-time text patches adjust
its minified output (see *Vite patches* below). Everything is pinned in
:file:`package.json` and locked in :file:`package-lock.json`; the built bundle is
committed, so end users never run Node.

Updating to the latest upstream version
---------------------------------------

..  code-block:: bash
    :caption: Bump the upstream editor

    cd <ext>/                      # vendor/webconsulting/docx-editor
    # 1. raise the three eigenpal entries in package.json to the new version
    #    (@eigenpal/docx-editor-core, -i18n, -react)
    rm -f package-lock.json
    npm install --no-audit --no-fund

    # 2. confirm the patches still find their anchors in the new build
    npm run test:build

    # 3. rebuild the committed bundle
    npm run build

    # 4. flush caches and verify in the backend (see checklist)
    ddev exec vendor/bin/typo3 cache:flush

Then commit the changed :file:`package.json`, :file:`package-lock.json`,
:file:`Resources/Public/Vite/docx-editor.js` and
:file:`Resources/Public/Vite/manifest.json`.

..  tip::

    Find the latest version with ``npm view @eigenpal/docx-editor-react version``.

If ``npm run test:build`` fails, a patch lost its anchor in the new build — see
the next section for how to re-anchor it. If it passes, the patches still apply
and you only need to re-verify in the browser.

Post-upgrade verification checklist
------------------------------------

#. Open a ``.docx`` from the Media file list (**Edit DOCX**) — the editor mounts.
#. The block-style dropdown lists exactly **Normal, H1, H2, H3, H4**.
#. Clicking **H2** (and the H1–H4 quick buttons) applies the heading.
#. **Save** writes back to FAL and the toast shows ``Saved to <path>``.
#. No console errors; toolbar borders render cleanly.

Vite patches
============

Both plugins live in :file:`Build/vite/plugins/` and are **chunk-agnostic**: they
scan every ``dist/*.mjs`` file by content pattern, so an upstream chunk rename
alone does not break them. ``npm run test:build`` asserts each anchor still
matches.

..  list-table::
    :header-rows: 1

    * - Plugin
      - Purpose
    * - :file:`heading4-fallback.js`
      - Appends ``Heading4`` to eigenpal's built-in fallback style array
        (upstream stops at Heading 3).
    * - :file:`style-dropdown-headings.js`
      - Forces the style dropdown to ignore the document's own styles and offer
        exactly **Normal + Heading 1–4**. Without it a Word file surfaces
        arbitrary names like "List Paragraph".
    * - :file:`popover-align.js`
      - Makes the editing-mode picker open **rightward** from its trigger
        (upstream right-aligns it, clipping off-screen in a narrow editor). The
        runtime clamp in :file:`docx-editor-toolbar.js` keeps it on-screen if
        rightward overflows.

Re-anchoring after a failed ``test:build``
-------------------------------------------

**heading4-fallback** — the fallback-array tail changed. Diff
``node_modules/@eigenpal/docx-editor-react/dist/chunk-*.mjs`` against
``HEADING3_FALLBACK_TAIL`` and update that constant. If the build already
contains ``styles.heading4``, upstream ships Heading 4 natively: delete the
plugin and its test.

**style-dropdown-headings** — upstream refactored the dropdown's option source.
**Do not edit existing ``SHAPES`` entries** (older fallback paths stay useful).
**Add a new entry** with a unique ``id`` (e.g. ``'1.7.x'``), a ``needle`` (the
smallest substring uniquely identifying the new option-source expression) and a
``transform`` that rewrites it to use ``FILTER_BODY``. If upstream adds a prop to
filter the dropdown, drop the plugin and configure ``<DocxEditor>`` instead.

..  list-table:: Known dropdown shapes
    :header-rows: 1

    * - eigenpal
      - shape id
      - option-source anchor
    * - ``1.2.x``
      - ``'1.2.x'``
      - ``!o||o.length===0?vo:o.filter(u=>u.type==="paragraph")``
    * - ``1.6.x``
      - ``'1.6.x'``
      - ``resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(``

To widen the dropdown, edit ``FILTER_BODY`` in
:file:`style-dropdown-headings.js` (e.g. add ``Title|Subtitle``); dropping
``Normal|`` leaves no in-dropdown route back to body text.

ICU labels
==========

Backend labels that need pluralization use **XLIFF 2 + ICU MessageFormat**, e.g.
``editor.collaborators``::

    {count, plural, one {1 editor online} other {# editors online}}

Server-side ``f:translate`` cannot resolve the count (presence is updated live in
JS), so the raw ICU string is passed to the client and resolved by
:file:`Build/Sources/docx-icu-format.js` (``formatIcu()``), a minimal resolver
using ``Intl.PluralRules`` for locale-aware plurals. It supports
``{var, plural, …}``, ``{var}`` and ``#``; add cases there if a new label needs
``select`` or offsets. Unit tests: :file:`Build/Sources/docx-icu-format.test.js`.

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
