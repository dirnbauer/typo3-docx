# DOCX Editor for TYPO3 (`docx_editor`)

[![CI](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.3-orange)](https://get.typo3.org/version/14)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue)](https://www.php.net/)

WYSIWYG editing of Word (`.docx`) files inside the TYPO3 backend, powered by
[eigenpal/docx-editor](https://github.com/eigenpal/docx-editor): open a file
from the file list, edit it full-page, save it straight back to FAL.

## What it is

- **Edit DOCX** action on `.docx` files in the file list; the editor is a backend
  *route*, not a module in the menu (like the core text-file editor).
- The DocHeader of core's own text-file editor: **Close** (asks before
  discarding unsaved changes, with the core dialog), **Save** with *Save and
  close* and *Save as…* (folder browser, then a file name dialog), and
  **Download**; Ctrl/Cmd+S saves. Feedback via backend notifications.
- Style dropdown curated to **Normal + Heading 1–4** with matching H1–H4
  toolbar shortcuts. The editor chrome, menus and popovers follow the backend's
  light and dark mode through TYPO3's design tokens; the document page stays
  white, as in Word.
- Presence ("2 editors online", a core status indicator) and revision-safe
  saves: a save is rejected with HTTP 409 when someone else stored a newer
  revision, and a warning callout offers to reload.
- English and German labels, error messages included; the scripts read them
  from the `docx_editor.messages` domain.
- Upstream: [`@eigenpal/docx-editor-*`](https://www.npmjs.com/package/@eigenpal/docx-editor-react)
  1.9.0, the last release of that package line; eigenpal continues the editor
  as `@docx-editor.dev/*` 2.x with a new API (see the developer guide).

> **Google Fonts:** the upstream editor tries to load Office typefaces from
> `fonts.googleapis.com`. The bundle blocks those requests
> (`typo3-disable-external-fonts.js`) and uses system/embedded fonts, so the
> backend CSP needs no Google hosts.

## Requirements

| | |
| --- | --- |
| TYPO3 | 14.3 LTS (`typo3/cms-core ^14.3.7`) |
| PHP | 8.4 or 8.5 (both gate CI) |
| Node.js | 22.12+ — only to rebuild the frontend bundle |

## Install

```bash
composer require webconsulting/docx-editor
vendor/bin/typo3 extension:setup
```

`extension:setup` creates `tx_docx_editor_session` and `tx_docx_editor_revision`.
The Vite bundle is committed; a normal install needs no Node.js.

## Configure

Nothing to configure — no TypoScript, no TSconfig. Access follows FAL: read
permission opens a document (read-only), write permission enables saving.
Routes: `Configuration/Backend/Routes.php` (editor) and
`Configuration/Backend/AjaxRoutes.php` (load, save, save-as, presence, revision).

## Use

1. **Media › Filelist**, pick a `.docx`, choose **Edit DOCX**.
2. Edit; save with **Save** or **Ctrl/Cmd+S**; the Save dropdown offers
   **Save and close** and **Save as…** (target folder, then file name).
3. **Close** returns to the folder; with unsaved changes it asks first.
4. If another editor saved meanwhile, a warning callout offers **Reload document**.

## Develop

```bash
composer install && npm ci
composer ci        # validate, lint, cgl, phpstan, unit, functional
composer assets    # npm ci, patch anchors, build, git diff --exit-code
```

Frontend sources live in `Build/Sources/` (vanilla custom element
`<typo3-docx-editor>` + React adapter) and `Resources/Public/JavaScript/`
(TYPO3 ES modules for the DocHeader and notifications). Both import their
labels as `~labels/docx_editor.messages`, which the bundle keeps external for
the import map; `npm run test:build` checks that every requested key exists
in English and German. After changing `Build/Sources/` run `npm run build` and
commit `Resources/Public/Vite/`; CI fails on drift. CSS in
`Resources/Public/Css/` needs no build.

Three Vite plugins in `Build/vite/plugins/` patch the minified upstream chunks
(Heading 4, curated dropdown, popover direction); `npm run test:build` asserts
each patch still finds its anchor. See the developer chapter for re-anchoring.

## Docs

- [Manual](Documentation/Index.rst): introduction, installation, usage, configuration and security
- [Developer guide](Documentation/Developer/Index.rst): architecture, chunk patches, quality gates
- [Changelog](CHANGELOG.md)

## License

GPL-2.0-or-later
