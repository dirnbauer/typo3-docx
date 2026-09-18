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
- Docheader buttons: back, download, save, save as (folder browser), plus
  Ctrl/Cmd+S. Save feedback via backend notifications.
- Style dropdown curated to **Normal + Heading 1–4** with matching H1–H4
  toolbar shortcuts; TYPO3 light/dark design tokens.
- Presence ("2 editors online") and revision-safe saves: a save is rejected
  with HTTP 409 when someone else stored a newer revision.
- English and German labels.

> **Google Fonts:** the upstream editor tries to load Office typefaces from
> `fonts.googleapis.com`. The bundle blocks those requests
> (`typo3-disable-external-fonts.js`) and uses system/embedded fonts, so the
> backend CSP needs no Google hosts.

## Requirements

| | |
| --- | --- |
| TYPO3 | 14.3 LTS (`typo3/cms-core ^14.3.7`) |
| PHP | 8.4 (8.5 runs in CI as an allowed failure) |
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

1. **File › Filelist**, pick a `.docx`, choose **Edit DOCX**.
2. Edit; save with the docheader button or **Ctrl/Cmd+S**; **Save as** picks a
   target folder and file name.
3. If another editor saved meanwhile, a warning banner offers **Reload**.

## Develop

```bash
composer install && npm ci
composer ci        # validate, lint, cgl, phpstan, unit, functional
composer assets    # npm ci, patch anchors, build, git diff --exit-code
```

Frontend sources live in `Build/Sources/` (vanilla custom element
`<typo3-docx-editor>` + React adapter) and `Resources/Public/JavaScript/`
(TYPO3 ES modules for the docheader and notifications). After changing
`Build/Sources/` run `npm run build` and commit `Resources/Public/Vite/`;
CI fails on drift. CSS in `Resources/Public/Css/` needs no build.

Three Vite plugins in `Build/vite/plugins/` patch the minified upstream chunks
(Heading 4, curated dropdown, popover direction); `npm run test:build` asserts
each patch still finds its anchor. See the developer chapter for re-anchoring.

## Docs

- [Manual](Documentation/Index.rst): introduction, installation, usage, configuration and security
- [Developer guide](Documentation/Developer/Index.rst): architecture, chunk patches, quality gates
- [Changelog](CHANGELOG.md)

## License

GPL-2.0-or-later
