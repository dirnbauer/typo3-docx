# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Fixed

- FAL breadcrumb trail rendered above the editor surface (Media-style path in the content pane).
- Toolbar and Radix dropdown panels no longer clip or misalign (`overflow: visible`, body-level overlay styles).
- Save notification now confirms the fileadmin path (`Saved to {storage / path}`).

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
