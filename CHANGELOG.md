# Changelog

All notable changes to this project are documented in this file.

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
