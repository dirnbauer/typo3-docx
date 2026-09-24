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
      - Adds :guilabel:`Edit DOCX` to the file list's buttons (list view).
    * - :file:`Classes/ContextMenu/EditDocxItemProvider.php`
      - Adds :guilabel:`Edit DOCX` to a .docx file's context menu, after
        :guilabel:`Edit metadata`; the tile view has no buttons, only this menu.
    * - :file:`Classes/EventListener/AllowEditorEngineInContentSecurityPolicy.php`
      - ``'wasm-unsafe-eval'`` and ``blob:`` images on the editor route only
        (see :ref:`security`).
    * - :file:`Build/Sources/webcon-docx-editor.js`
      - ``<webcon-docx-editor>``: the editor as a custom element with a
        byte-level API (:ref:`developer-integration`).
    * - :file:`Build/Sources/typo3-docx-editor.js`,
        :file:`docx-editor-api.js`
      - ``<typo3-docx-editor>``: the module element around it — FAL load and
        save through the AJAX routes, *Save as…*, presence, newer-revision
        warning.
    * - :file:`Build/Sources/editor/`
      - The Vue composition of ``@docx-editor.dev/vue``
        (:file:`EditorShell.vue`: menus, toolbar, rulers, navigation, popups),
        the curated style picker and H1–H4 buttons, the content-control mode,
        the German catalogue overlay (:file:`i18n/de.json`) and
        :file:`curated-styles.js`.
    * - :file:`Resources/Public/JavaScript/toolbar.js`, :file:`notify.js`
      - TYPO3 ES modules (import map ``@webconsulting/docx-editor/``):
        DocHeader buttons, the unsaved-changes dialog (core labels from
        ``~labels/backend.alt_doc``), folder browser, file name dialog,
        notifications.
    * - :file:`Resources/Public/Css/Editor.tokens.css`,
        :file:`Editor.base.css`
      - The editor's design tokens mapped to TYPO3's (so dark mode follows
        the backend) and the module layout; registered after the bundle CSS.

Every script imports its labels from the ``docx_editor.messages`` domain
(``import labels from '~labels/docx_editor.messages'``); the Vite bundle keeps
``~labels/`` external so the backend import map resolves it at runtime.
Plurals are ICU messages. :file:`Build/Tests/labels.test.js` (run by
``npm run test:build``) fails when a script asks for a key that is missing in
English or German — the label providers throw on unknown keys.

Server-side errors are :php:`DocxEditorException` instances whose message is
a key of the same domain; the error page and the JSON API translate it into
the backend user's language (:php:`DocxEditorException::localizedMessage()`).

The editor engine
=================

``@docx-editor.dev/core`` keeps the opened OOXML package as its model and
serializes it back on save: edited parts are rewritten from typed nodes,
unmodeled elements and attributes are re-emitted in place, other parts and
media are copied. ``serialize()`` always goes through that package
serializer — never through a "new document" export — which is why content
controls, bookmarks, custom XML parts and custom properties survive.

Only Apache-2.0 packages are used: ``@docx-editor.dev/core``, ``/vue``,
``/i18n`` and ``/fonts`` (fonts under SIL OFL 1.1 and GUST). The review
module, comment rail, collaboration and PDF export live in the commercial
``@docx-editor.dev/pro`` and ``@docx-editor.dev/editor-api``; the Review menu
therefore keeps only paragraph marks and forms protection, and the toolbar
has no comment button.

Printing (:file:`Build/Sources/editor/print.js`) needs neither: the engine
paints only the pages near the viewport, so ``preparePrint()`` shows the
document at 100 % in a viewport as tall as the document, waits until every
page is painted, copies the pages into a print-only container and adds one
named ``@page`` rule per paper size (``size`` from the page geometry, no
margin — the margins are part of the painted page). The print stylesheet in
:file:`Editor.base.css` hides everything else while
``html.webcon-docx-printing`` is set; ``afterprint`` restores zoom and scroll.
No CSP change is needed: the pages' pictures are the ``blob:`` URLs the
editor route already allows, and the backend policy allows inline styles.

Curated styles
--------------

The engine refuses a paragraph style the document does not define, and Word
leaves unused headings undefined (latent). :file:`curated-styles.js` maps the
roles Normal and Heading 1–4 to the document's own style IDs by Word name
(``Standard`` / ``berschrift1`` in a German Word), adds Word's definitions for
missing headings to :file:`styles.xml` when a document is opened
(``materializeCuratedStyles()``), and removes the ones no paragraph, list
level or style refers to before the bytes leave the editor
(``dropUnusedMaterializedStyles()``).

..  _developer-integration:

Integration API
===============

The bundle (import map ``@webconsulting/docx-editor/editor.js``) defines and
exports ``WebconDocxEditorElement`` (``<webcon-docx-editor>``),
``Typo3DocxEditorElement`` (``<typo3-docx-editor>``) and ``mountDocxEditor()``.

``<webcon-docx-editor>``
    Attributes ``locale`` (``de``/``en``), ``readonly`` and
    ``content-controls="show"``; property ``labels``; methods
    ``load(bytes)`` (resolves when the document is shown),
    ``serialize()`` (``Uint8Array``), ``markClean(revision)``, ``print()``
    (the browser's print dialog, one sheet per page at the document's paper
    size; also :guilabel:`File › Print` and :kbd:`Ctrl/Cmd+P`) and
    ``preparePrint()`` (the pages ready for print media without the dialog;
    resolves to a cleanup function — for headless PDF rendering); properties
    ``revision``, ``dirty`` and ``editor`` (the core editor instance); events
    ``docx-editor:ready``, ``docx-editor:change`` (``{dirty, revision}``),
    ``docx-editor:save-request`` (File › Save, the toolbar, :kbd:`Ctrl/Cmd+S`),
    ``docx-editor:error`` and ``docx-editor:font-error``. The element needs a
    height.

``content-controls="show"``
    Draws every content control's boundary and tag, and removes the *Remove*
    actions from the toolbar and from the control popup — for documents whose
    controls map to TYPO3 records.

``<typo3-docx-editor>``
    Adds FAL loading and saving, presence and the newer-revision warning.
    ``load-url`` replaces the load route (GET, answers
    ``{ok, data, revision}``, ``data`` base64); ``save-url`` replaces the save
    route (POST ``{file, revision, data}``, answers ``{ok, revision}``). Public:
    ``save()``, ``saveAsToFolder(folder, name)``, ``dirty``,
    ``editorElement``.

Page round trip
===============

The engine lives in :file:`Classes/PageSync/` and is independent of the
embedded editor; the backend screens and the commands only call
:php:`PageSyncService`.

..  list-table::
    :header-rows: 1
    :widths: 25 75

    * - Namespace
      - Responsibility
    * - ``Document``
      - The document model: headings, paragraphs with marks and links,
        lists, tables, figures, quotes, code, content controls, bookmarks.
    * - ``Ooxml``
      - :php:`DocumentReader` / :php:`DocumentWriter` on ``ZipArchive`` and
        DOM: XXE-safe parsing, archive limits, styles by Word's built-in
        names (localised style ids work), numbering, fields and hyperlinks,
        tracked changes, text boxes, embedded pictures, the Word template.
    * - ``Manifest``
      - Control tags (``typo3:tt_content:12:bodytext``, short
        ``typo3:#3:2`` references for long Content Blocks names) and the
        signed custom XML part with the exported value of every field, the
        stored value of every link field and, for every picture, the file
        reference and file it stands for and the hash of its embedded copy.
    * - ``Schema``
      - Element shapes from the TCA schema API (sub-schemas,
        ``columnsOverrides``, collections) and the role of every field
        (heading, body, image, quote, item title…).
    * - ``Export``, ``Field``, ``Html``
      - Field values to blocks and back; rich text through a canonical HTML
        form so that only real edits count as changes. Pictures as scaled
        copies (:php:`PictureDerivatives`), link fields as labels
        (:php:`LinkLabels`).
    * - ``Segmentation``, ``Matching``
      - New content split into parts; every allowed type scored on how well
        the part fills its fields; Jev as tie-breaker.
    * - ``Plan``, ``Apply``
      - Three-way comparison per field (Word, TYPO3 now, the export), the
        reviewable plan, and the DataHandler data and command maps.

Choosing content types in your own code
---------------------------------------

Implement :php:`Webconsulting\DocxEditor\PageSync\Matching\MappingRuleInterface`
in a site package to raise (or add) a type for parts you recognise — the
interface's service tag is applied automatically:

..  code-block:: php

    final readonly class TeamMemberRule implements MappingRuleInterface
    {
        public function __construct(private FieldPlacer $placer) {}

        public function propose(PartShape $part, ContentTypeCandidate $candidate): ?Proposal
        {
            // A heading, a portrait and a few lines of text: one of our team cards.
            if ($candidate->cType !== 'site_teammember' || $part->images === [] || $part->heading === null) {
                return null;
            }
            $placement = $this->placer->place($part, $candidate->shape);

            // The fit decides; the preference tips the balance against equally good types.
            return new Proposal($candidate->cType, $placement->score(), $placement, 'team-member', preference: 0.05);
        }
    }

Or listen to :php:`ModifyContentTypeProposalsEvent` to re-rank, drop or add
proposals per part. Proposals for types the editor may not create are dropped
after the event.

Jev
---

With webcon_jev installed, :php:`JevContentTypeChooser` builds a transient
decision (uid 0, identifier ``docx_editor.content_type``) with one *choice*
question per contested part — the options are the contending types, each
described by its label and fields — and runs it through
:php:`DecisionRunner::run()` with the run context
``docx_editor_page_import``. The switch, token, cache, budget guard, fallback
and run log are webcon_jev's. Parts are batched to stay inside the API's
request limits. webcon_jev is optional: the runner is a nullable constructor
argument, so without the extension the import boots and matches by structure.

Frontend build
==============

..  code-block:: bash

    npm ci
    npm run test:build   # round-trip, chrome, licence, catalogue and label tests
    npm run build        # -> Resources/Public/Vite/

Vite compiles the Vue single-file components; the bundle carries Vue's
runtime-only build, so nothing is compiled in the browser. Output:

..  list-table::
    :header-rows: 1

    * - Path
      - Content
    * - :file:`docx-editor.js`, :file:`docx-editor.css`
      - The module (stable names, no manifest; TYPO3 adds cache-busting).
    * - :file:`chunks/`
      - Code the engine loads on demand (EMF/WMF and TIFF images, the shaper
        loader).
    * - :file:`assets/`
      - :file:`harfbuzz-*.wasm` and the font files, fetched relative to the
        module.
    * - :file:`licenses/`
      - Licences of everything bundled (Vite's licence report, with a note
        for packages that ship no licence text), the fonts' licences,
        HarfBuzz's and the upstream packages' third-party notices.

Commit :file:`Resources/Public/Vite/` — the CI ``assets`` job rebuilds and
fails on ``git diff``. In the DDEV lab, build to a scratch folder
(``npx vite build --outDir /tmp/docx-editor-vite``) and copy the result in:
the file sync can delete freshly emptied output directories.

Round-trip tests
----------------

:file:`Build/Tests/round-trip.test.js` opens the fixtures in
:file:`Build/Tests/Fixtures/` with the engine the bundle ships (headless, in
happy-dom), saves, re-opens and compares every part of the package in a
namespace-aware canonical form (:file:`Build/Tests/lib/ooxml-canonical.js`):
untouched saves change nothing, a typed edit changes only its paragraph, and
content controls, bookmarks, tracked changes, footnotes, custom XML and custom
properties survive. :file:`generate-fixtures.py` (python-docx) documents how
the fixtures were made.

Free features only
------------------

:file:`Build/Tests/chrome.test.js` builds :file:`Build/Sources/editor/mount.js`
with Vite into a temporary folder (:file:`Build/Tests/lib/mounted-editor.js`),
mounts it headless on the fixtures — editable and read-only — and fails when
the toolbar, a menu, the context menu or a Word shortcut offers anything of
``@docx-editor.dev/pro``: a control of the engine's ``review`` chrome group
other than paragraph marks and forms protection, or a label such as
*Suggesting*, *Add a comment…*, *Accept all changes shown* or a "requires the
pro review module" hint. It also checks that tracked changes show in their
final state and that tracked changes and comments survive a save.

:file:`Build/Tests/licenses.test.js` fails when a commercial docx-editor.dev
package (``/pro``, ``/editor-api``, ``/docx-to-pdf``) appears in
:file:`package-lock.json` in any role, when a runtime dependency or a bundled
package (as listed in :file:`licenses/THIRD-PARTY-LICENSES.md`) has a licence
outside the allowlist, when a bundled package ships without licence text, or
when a font or :file:`harfbuzz.wasm` ships without its licence.

Upgrading the engine
--------------------

The docx-editor.dev packages release as one version group; keep them on the
same ``~2.x.y``. After an upgrade run ``npm run test:build`` —
:file:`Build/Tests/i18n.test.js` fails for overlay keys upstream has
translated since (drop them from :file:`i18n/de.json`) — rebuild, and check in
the backend that the style picker, the H1–H4 buttons and the Review menu
behave. A new chrome control that belongs to the commercial package fails
:file:`Build/Tests/chrome.test.js`; hide it in :file:`EditorShell.vue`.

Quality gates
=============

..  code-block:: bash

    composer ci        # validate, lint, cgl, phpstan (level 8), unit, functional
    composer assets    # npm ci, test:build, build, git diff --exit-code

Functional tests use sqlite by default (:file:`Build/phpunit/FunctionalTests.xml`);
CI runs them against MariaDB 10.11. They request the real backend routes with
a fixture storage and cover the editor page, the document API (load, save,
409, save-as), the collaboration API and the route-scoped
Content-Security-Policy.

The page round trip's functional tests run on a fixture site with core and
Content-Blocks-shaped element types, a workspace, a translation and three
backend users: export, round trip, conflicts, workspaces, translations,
permissions, new pages, the preview/apply digest check, the CLI, the backend
routes and screens, a document edited and saved by Microsoft Word, and Jev
through webcon_jev with a scripted client (``pagesync_jev_test`` replaces the
HTTP client — no test calls the API). One test class boots without
webcon_jev.
