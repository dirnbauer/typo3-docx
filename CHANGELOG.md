# Changelog

All notable changes to this project are documented in this file.

## [2.3.0] - 2026-09-23

Two limits of the Word ↔ page round trip are gone: pages with many pictures
fit the upload limit, and link fields say where they point.

### Added

- **Compact pictures.** A page's pictures are embedded as copies made by
  TYPO3's image processing, in the file's own format: as many pixels as Word
  needs to show the picture (at most text-wide) at
  `pageSync.pictureResolution` pixels per inch (default 150, Word's "Web"),
  and no edge longer than `pageSync.pictureMaxEdge` (default 2000). Smaller
  pictures, GIFs and pictures TYPO3 cannot process are embedded as they are.
  Lab page 1121 (27 PNG pictures of about 1.9 MB each) exported to 51.1 MB
  (48.8 MiB) and could not come back through the 25 MiB upload limit; it now
  exports to 17.8 MB (17.0 MiB) and imports as 37 × unchanged.
- **Pictures keep their identity.** Every exported picture is named after its
  file reference (`wp:docPr` name `typo3:sys_file_reference:<uid>`), and the
  signed manifest lists, per picture, the reference, the file, the file's
  SHA-1 and the SHA-1 of the embedded copy. A picture that comes back with
  that copy is the same reference (alt text and caption edits apply to it;
  the file stays), also when a word processor dropped the name; a picture
  replaced in Word — inserted anew or via *Change Picture*, which keeps the
  name — is stored as a new FAL file as Word holds it; an exported picture
  used a second time refers to its file again instead of storing the copy.
  Documents exported by 2.2 (full files, no picture list) are recognised as
  before.
- **Readable link fields.** A read-only link field shows what it points to,
  resolved through the LinkService the way the backend's link field explains
  it: *Page: Technical features (/Desiderio/Technical features/)* (title in
  the document's language, path in the page tree, `#section` for fragments),
  *File: brochure.pdf*, *Folder: …*, the record's title for record links
  (table from the page's `TCEMAIN.linkHandler`), *E-mail: …*, *Phone: …* or
  the URL; a target that is gone or hidden from the user shows the stored
  link with "not found, or not visible to you". The hyperlink keeps the
  stored target, the manifest the stored value (`<t3:field value="…">`); the
  import never writes a link field.
- `docx-editor:page:export` prints the document's size and warns when an
  import would refuse it; `--out` with a trailing `/` creates the directory.

### Changed

- Comparing pictures on import no longer reads the files: a picture's
  identity is its file's SHA-1 from the FAL index.
- The manifest signature covers the new picture list and link values; a
  manifest without them signs exactly as 2.2 did, so older documents stay
  trusted.
- CI installs ImageMagick for the picture tests.

### Removed

- The PHPStan mapping of the `backend.user` request attribute, which TYPO3
  never sets (2.2.1).

## [2.2.1] - 2026-09-23

### Fixed

- *Edit in Word* failed on every real backend request with "Die Seite konnte
  nicht geöffnet werden — No backend user": the page-sync API read the backend
  user from a `backend.user` request attribute, which TYPO3 never sets. The
  same lookup made the file editor record every save as user 0 (so the
  newer-version warning could not name who saved) and kept the editor chrome
  English for German editors. All three now read `$GLOBALS['BE_USER']`, like
  the rest of the extension.
- The functional tests injected that attribute by hand, which is why they
  passed; they now build requests the way TYPO3 does, so the regression is
  covered.

## [2.2.0] - 2026-09-23

Printing is back — 1.x's *File › Print* went with the old engine, and
docx-editor.dev prints only through its commercial package.

### Added

- **Print** in the DocHeader (file editor and *Edit in Word*), as *File ›
  Print* and on Ctrl/Cmd+P: the browser's print dialog with the pages as the
  editor lays them out, one sheet per page at the document's paper size
  (named `@page` rules per section: A4, Letter, landscape…), without control
  frames, header/footer hints or page shadows. *Save as PDF* in the dialog
  makes a PDF.
- `<webcon-docx-editor>` `print()` and `preparePrint()` (the pages ready for
  print media without the dialog — resolves to a cleanup function, for
  headless PDF rendering); `<typo3-docx-editor>` `print()`.
- Icon `docx-editor-print` (core ships no printer icon).

### Notes

- The engine paints only the pages near the viewport; printing shows the
  document at 100 % in a viewport as tall as the document until every page is
  painted, copies the pages and restores zoom and scroll after `afterprint`.
  Checked headless with Chromium's print media: 23 A4 pages (a lab page with
  16 pictures) → a 23-page A4 PDF; a Letter document with a landscape section
  → Letter and Letter-landscape sheets.
- `@docx-editor.dev/docx-to-pdf` is not published on npm, so the browser's
  print pipeline it is: no new dependency and no CSP change (pictures are the
  `blob:` URLs the editor routes already allow).

## [2.1.0] - 2026-09-23

Edit pages in Word: a page and its content elements as one Word document,
edited in the backend or in any word processor and imported back after a
review of every change; Word documents imported as new pages.

### Added

- **Word menu** in the Page module's DocHeader and the page tree's context
  menu: *Edit in Word*, *Download as Word document*, *Import Word document as
  subpages…* — each only where the backend user may use it.
- **Edit in Word** (`docx_editor_page`): the page in `<webcon-docx-editor>`
  with every content control drawn. The page title, every content element and
  every field (collection items of Content Blocks elements included) are
  content controls tagged `typo3:<table>:<uid>[:<field>]`; a signed custom XML
  part records the exported value of every field. Save, *File > Save* and
  Ctrl/Cmd+S open the review; *Upload Word document…* reviews a document
  edited elsewhere.
- **Review** before anything is written: new elements with the proposed
  content type and the alternatives (and whether structure or Jev chose it),
  changed fields, conflicts decided per field (TYPO3 wins by default),
  deletions only when ticked, translations, the new order. The import goes
  through the DataHandler as the current user, in the current workspace and
  language (RTE transformation and HTML sanitizer included); pictures are
  stored in `1:/user_upload/word/{page}/`, deduplicated by SHA-1. A preview is
  kept for its user for a day; if TYPO3 changed before it is applied, nothing
  is written.
- **Import as subpages** (`docx_editor_page_new`): one page, a page per
  Heading 1 or a page per page break; hidden by default, after the existing
  subpages.
- **Content type matching**: every part is scored against the types the New
  Content Element wizard allows in the column (backend layout, TSconfig,
  permissions) on how well it fills their fields, from the TCA schema.
  Extensible with `MappingRuleInterface` (auto-tagged) and
  `ModifyContentTypeProposalsEvent`.
- **Jev** (optional, `webconsulting/webcon-jev ^0.2.1`): where types fit
  equally well, a transient decision (`docx_editor.content_type`) asks Jev to
  choose, through webcon_jev's `DecisionRunner` with the run context
  `docx_editor_page_import`. Below the confidence threshold (0.6) the
  structural choice stays and the element is marked for review.
- **CLI**: `docx-editor:page:export <page> [--language] [--workspace] [--out]`
  and `docx-editor:page:import <file> --pid=|--parent= [--split] [--language]
  [--workspace] [--apply] [--confirm-deletions] [--prefer=typo3|word]
  [--no-jev]` — a dry run unless `--apply` is given.
- Extension settings `pageSync.*`: picture folder, new pages hidden, upload
  limit, Word template, excluded content types, Jev switch, threshold,
  candidates and cache lifetime.
- Word documents are read without DTDs or entities and within archive, part
  and ratio limits. Elements whose content controls a word processor removed
  are recognised by their bookmarks, then by their text.

### Changed

- The engine's CSP allowance (`'wasm-unsafe-eval'`, `img-src blob:`) also
  applies to the `docx_editor_page` route — and only there.
- CI installs `zip`, `dom` and `sodium`; the extension requires `ext-zip`,
  `ext-dom` and `ext-libxml`.

## [2.0.0] - 2026-09-23

A new editor engine. `@eigenpal/docx-editor-*` 1.9 (React), deprecated
upstream, gives way to its successor docx-editor.dev 2.21
(`@docx-editor.dev/core`, `/vue`, `/i18n`, `/fonts` — all Apache-2.0, fonts
OFL/GUST), composed in Vue. PHP routes, tables and permissions are unchanged;
the lab and site constraint becomes `^2.0`.

### Changed

- **Engine:** Word-faithful layout (HarfBuzz text shaping, Word's line and
  page breaking, rulers, navigation pane, page setup and paragraph dialogs).
  The engine keeps the opened package as its model and serializes it back:
  untouched saves change nothing, edits touch only what was edited, and
  content controls, bookmarks, custom XML, custom properties, tracked changes,
  comments and unmodeled markup survive. 1.x rewrote styles into direct
  formatting, dropped page breaks, section columns and table looks, and
  duplicated images on its full-save path.
- **Vue instead of React:** `Build/Sources/editor/*.vue` compose
  `@docx-editor.dev/vue` (menus, toolbar, viewport, popups). The components
  are compiled at build time and the bundle carries Vue's runtime-only build —
  no template compiler, no `'unsafe-eval'`.
- **Curated styles without chunk patches:** the style picker is the
  toolbar's own `StylePicker` with Normal + Heading 1–4 as its items, the
  H1–H4 buttons use `useParagraphStyle()`. Styles are matched by Word name, so
  German Word files (`Standard`, `berschrift1`) work; latent headings get
  Word's definitions while the document is open and are removed again on save
  unless applied. The three Vite plugins that patched eigenpal's minified
  chunks are gone, and so is the popover clamp in `toolbar.js`.
- **Fonts:** metric-compatible open fonts for Calibri, Cambria, Arial, Times
  New Roman, Courier New and Century Gothic (`@docx-editor.dev/fonts`) ship in
  `Resources/Public/Vite/assets/` and load same-origin, per family, on demand.
  `typo3-disable-external-fonts.js` is gone — nothing calls a font CDN.
- **German:** about 200 strings the upstream German catalogue leaves
  untranslated are completed in `Build/Sources/editor/i18n/de.json`.
- **Menus:** File offers Save and Page setup (no Open, no converter-based
  export); Review offers paragraph marks and forms protection.
- Theme: `Editor.tokens.css` maps the docx-editor.dev tokens to TYPO3's
  (surface containers for notices and selections); `Editor.toolbar.css` is
  gone, `Editor.base.css` lays out the composed editor.
- Bundle: `docx-editor.js` 2,877 kB (833 kB gzip, was 2,098 / 619 kB),
  `docx-editor.css` 132 kB (21 kB gzip, was 48 / 8 kB), lazy chunks for
  EMF/WMF, TIFF and the shaper loader, `harfbuzz.wasm` 427 kB (176 kB gzip)
  and 8.2 MB of fonts of which a document loads only what it uses.

### Added

- `AllowEditorEngineInContentSecurityPolicy`: `script-src
  'wasm-unsafe-eval'` and `img-src blob:` on the `docx_editor` route only;
  unit and functional tests prove other backend routes keep the core policy.
- `<webcon-docx-editor>`: the editor as a reusable element — `load(bytes)`,
  `serialize()`, `dirty`/`revision`/`markClean()`, `docx-editor:ready`,
  `:change`, `:save-request`, `:error`, `:font-error`; attributes `locale`,
  `readonly` and `content-controls="show"` (all control boundaries visible,
  no Remove actions).
- `<typo3-docx-editor>` attributes `load-url` and `save-url` to load from or
  save to other endpoints with the same JSON payloads, and
  `content-controls`.
- Round-trip fidelity tests (`Build/Tests/round-trip.test.js`, headless in
  happy-dom) on generated fixtures with styles, lists, tables, images,
  headers and footers, fields, comments, content controls, bookmarks, tracked
  changes, footnotes and custom XML; a test for the German overlay.
- `Resources/Public/Vite/licenses/`: licences of all bundled code and fonts.

### Removed

- React, react-dom, `@vitejs/plugin-react` and every `@eigenpal/*` package.
- Features of the 1.x editor that docx-editor.dev ships only in its commercial
  `@docx-editor.dev/pro`: suggesting mode and accept/reject of tracked
  changes, markup views, and comment threads. Existing tracked changes and
  comments are kept on save.

## [1.5.0] - 2026-09-23

The editor page becomes a native TYPO3 v14 citizen: core DocHeader buttons
and dialogs, core components for presence and conflicts, translated errors,
and a theme that holds up in dark mode.

### Added

- **Save and close** in the Save dropdown, and **Close** with the core
  "unsaved changes" dialog (keep editing, discard, save and close — the labels
  of record editing). Reloading or navigating away with unsaved changes lets
  the browser warn.
- An `<h1>` ("Edit example.docx") with the presence badge next to it.
- Translated error messages: `DocxEditorException` carries a label key of the
  `docx_editor.messages` domain; the error page and the JSON API answer in the
  backend user's language (English and German, every error message).
- `Build/Sources/labels.test.js`: every label key a script asks for must exist
  in English and German.

### Changed

- The DocHeader uses core buttons: `ComponentFactory::createCloseButton()`
  instead of the custom "Back to Media", the core Save button as a split button
  with *Save and close* and *Save as…*, and Download. Close honours a
  `returnUrl` like core's text file editor and falls back to the file's folder.
- *Save as…* asks for the file name in a TYPO3 modal instead of
  `window.prompt()`.
- The newer-revision banner is a core warning callout with a
  *Reload document* button; presence is a core badge with a live
  `<typo3-backend-status-indicator>` and the editors' names as tooltip.
- The error page renders in the `Module` layout, so it has a DocHeader (with
  Close), a heading and a core callout.
- Scripts read their labels from `~labels/docx_editor.messages` (ICU plurals
  through the core label provider); the `data-labels` JSON attribute, its PHP
  builder and the hand-written ICU formatter (`docx-icu-format.js`) are gone.
- The theme maps eigenpal's shadcn tokens with relative colour syntax
  (`from var(--typo3-…) h s l`), so eigenpal's own utility classes follow the
  backend in light and dark mode; `--doc-surface` and the other surface tokens
  are mapped as well.
- *File › Open* is hidden: it would have replaced the TYPO3 file with a local
  one.
- The loading state shows the core spinner.
- Class constants are typed; PHPStan reports missing `#[\Override]`
  attributes.
- PHP 8.5 gates CI next to 8.4, the functional suite runs on both;
  `actions/checkout` v7, `actions/setup-node` v7 with Node.js 24.

### Fixed

- Menus and popovers rendered with a white background in dark mode: the
  theme's portal selector (`body > div > .ep-root`) no longer matched where the
  popovers mount, and `--doc-surface` was not mapped.
- eigenpal's shadcn tokens were mapped to HSL triples inside `light-dark()`
  and to plain TYPO3 colours, both invalid in `hsl(var(--…))`, so every
  utility using them fell back to transparent or inherited colours.
- The presence dot used hard-coded hex colours.

### Removed

- `Build/Sources/docx-icu-format.js` and its test, the `editor.save`,
  `editor.backToMedia`, `editor.saveAsPrompt`, `editor.remoteUpdate` and
  `error.missingFile` labels.

## [1.4.0] - 2026-09-18

Behaviour-preserving restructuring of the PHP layer and the JavaScript glue,
frontend toolchain refresh, and controller test coverage.

### Added

- Functional tests for the document API (load, save, stale-revision 409,
  save-as with duplicate suffix, payload validation) and the collaboration API
  (join, heartbeat, presence, leave, revision); shared
  `Tests/Functional/AbstractBackendRouteTestCase`.
- Unit tests for `DocxEditorException::getStatusCode()`, the JSON helper
  methods and `DocxFileService::canWrite()`.
- `composer assets` script (npm ci, patch anchors, build, drift check) as the
  local counterpart of the CI `assets` job.

### Changed

- **Labels:** `locallang_mod.xlf` merged into `locallang.xlf`; all PHP lookups
  use the `docx_editor.messages:` translation domain. JavaScript receives every
  label through one `data-labels` JSON attribute instead of ten `data-label-*`
  attributes plus a heading JSON.
- **Assets:** the Vite bundle has stable file names
  (`Resources/Public/Vite/docx-editor.{js,css}`); `ViteAssetResolver` and
  `manifest.json` are gone. Import map: `@webconsulting/docx-editor/editor.js`,
  `toolbar.js`, `notify.js`.
- `<typo3-docx-editor>` is a vanilla custom element; the `lit` dependency is
  removed (bundle -48 kB). The React host owns the save/save-as API instead of
  mutating a shared stub object; `use-typo3-docx-editor-options.jsx` and
  `docx-labels.js` are folded in or deleted.
- Presence sessions are deleted when stale or left instead of soft-deleted
  (`deleted` column dropped from `tx_docx_editor_session`);
  `CollaborationSessionService::join()` returns an `int`.
- `DocxEditorException` carries the HTTP status explicitly
  (`getStatusCode()`); API controllers share `respond()`, `stringValue()`,
  `intValue()`.
- `AddDocxEditFileActionListener` catches only `DocxEditorException`.
- Vite 6 → 8 (rolldown), `@vitejs/plugin-react` 4 → 6, target `es2022`.
- `composer.lock` is no longer committed; `phpstan/phpstan-strict-rules`
  (never configured) removed; `ext_localconf.php`, `Build/Scripts/runTests.sh`,
  `Build/Sources/README.md` and the unused `Editor.css` wrapper deleted.
  `composer ci` is the single local entry point.
- Documentation trimmed to what exists; Security folded into Configuration;
  Changelog chapter added.

### Fixed

- The "Save failed" notification always showed the English fallback: the
  attribute was `data-label-saveFailed` (HTML lower-cases it, so the dataset
  key never matched).
- Heading labels were emitted with `JSON_HEX_QUOT`, which makes the attribute
  value invalid JSON; the client silently fell back to `H1`/`Heading 1`.
- "Save as" with content that fails TYPO3's resource consistency check now
  answers with a JSON 415 instead of an uncaught `ResultException`.
- Missing `file` / `sessionUid` keys in JSON bodies no longer trigger
  "Undefined array key" warnings.

## [1.3.0] - 2026-09-13

### Added

- Unit tests for the editor helpers: save-path handling and allowed file types
  (`DocxFileService`), request/locale resolution (`EditorRequestResolver`) and
  the JSON API envelope (`AbstractDocxApiController`).
- Functional tests that request the `docx_editor` route for a fixture `.docx` in
  a test storage and assert the rendered editor, plus revision and presence
  coverage: `Build/phpunit/FunctionalTests.xml` (sqlite locally, MariaDB in CI).
- `EditorRequestResolver` service: file identifier (`file` / `target` /
  module data) and editor locale, extracted from `EditorController`.

### Changed

- Requires PHP 8.4 and TYPO3 `^14.3.7`.
- Upstream editor updated to `@eigenpal/docx-editor-*` 1.9.0 (React 19.3,
  Lit 3.3.3); the committed Vite bundle was rebuilt.
- The `style-dropdown-headings` and `popover-align` chunk patches lost their
  anchors in the 1.9.0 dist because the minifier renamed identifiers. Both gained
  identifier-agnostic regular-expression shapes, so future renames no longer
  break them; the older literal shapes are kept.
- PHPStan raised from level 6 to **level 8** (no baseline), now also analysing
  `Configuration/` and `Tests/`; coding standards enforced with the
  `typo3/coding-standards` ruleset.
- Services, controllers and the event listener are `readonly` classes; backend
  user ids are read via `getUserId()` instead of `user['uid']` offsets.
- A single `.github/workflows/ci.yml` runs lint, CGL, PHPStan, unit (PHP 8.4,
  plus 8.5 as an allowed failure), functional (MariaDB 10.11) and the asset
  build, which fails if the committed bundle drifts from a fresh `npm run build`.

### Fixed

- The "no file selected" error page rendered the raw label key
  `docx_editor.mod:error.missingFile` instead of the translated message.
- The file list **Edit DOCX** action resolved its title through a nonexistent
  `docx_editor:` translation domain and fell back to the raw key.
- FAL breadcrumb trail rendered above the editor surface (Media-style path in the content pane).
- Toolbar and Radix dropdown panels no longer clip or misalign (`overflow: visible`, body-level overlay styles).
- Save notification now confirms the fileadmin path (`Saved to {storage / path}`).

### Security

- `npm audit fix` cleared the `browserslist` and `baseline-browser-mapping`
  advisories; `npm audit --audit-level=high` and `composer audit` are CI gates.

## [1.2.0] - 2026-06-04

### Added

- Split editor theme CSS into `Editor.tokens.css`, `Editor.base.css`, and `Editor.toolbar.css`.
- `docx-editor-notify.js` TYPO3 module for save feedback via the Notification API.
- `docx-labels.js` and JSON `data-heading-labels` attribute for translated H1–H4 labels.
- `use-typo3-docx-editor-options.jsx` hook for TYPO3-specific React editor wiring.
- Fluid partial `RemoteRevisionBanner` for remote-revision conflict UI.
- Heading 4 Vite patch extracted to `Build/vite/plugins/heading4-fallback.js` with `npm run test:build`.
- `Build/Sources/README.md` documenting frontend layout and rebuild workflow.

### Changed

- Formatting toolbar: compact controls, multi-line wrap, no horizontal scroll.
- `docx-editor-toolbar.js`: `DocxEditorToolbarController` replaces `window.*` listener flags.
- `EditorController`: shared `addBackToMediaButton()` and `buildHeadingLabelsJson()`.
- Save status flows directly from Lit to `notifyDocxEditorStatus()` (no custom event bridge).
- CI assets suite runs `npm run test:build` before the production build.

## [1.1.0] - 2026-06-04

### Added

- TYPO3 docheader integration: FAL breadcrumbs, standard button bar (back, save, save as).
- H1–H4 heading shortcuts in the formatting toolbar and Heading 4 in the style picker fallback.
- Save feedback via `@typo3/backend/notification.js` instead of inline status text.
- Google Fonts CSP guard (`typo3-disable-external-fonts.js`) documented prominently in README and Security docs.

### Changed

- Editor chrome and toolbar icons mapped to TYPO3 backend design tokens (light/dark).
- `Editor.css` loads after the Vite bundle so TYPO3 theme overrides win.
- JSON API controllers parse `application/json` request bodies for TYPO3 compatibility.
- ICU collaborator labels split into plain `one` / `other` XLIFF units for Fluid/JS.

### Fixed

- Save API exposed on the Lit host element (`typo3-docx-editor`) so toolbar save works.
- File resolution via `retrieveFileOrFolderObject()` and `target` query alias.
- PHPStan issues in `EditorController` and `DocxFileService`.

## [1.0.0] - 2026-06-04

### Added

- Initial release for TYPO3 14.3+ and PHP 8.2+.
- **Edit DOCX** primary action in the Media module file list for `.docx` files.
- Backend editor route under the Media module with
  [eigenpal/docx-editor](https://github.com/eigenpal/docx-editor).
- FAL load/save via backend AJAX routes with permission checks.
- Multi-user **presence** (active editors) and **revision-aware** saves with
  conflict detection (HTTP 409).
- Lit custom element glue (`typo3-docx-editor`) and pre-built Vite bundle.
- XLIFF 2.0 labels (English and German) with ICU plural messages.
- `Build/Scripts/runTests.sh` for local and CI quality gates.
- GitHub Actions CI matrix (PHP 8.3 and 8.4).

### Security

- Document API routes require an authenticated backend session.
- FAL read/write permissions are enforced per storage and file.
- Saves reject stale revisions when another editor saved a newer version.
