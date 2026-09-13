# DOCX Editor for TYPO3 (`docx_editor`)

[![CI](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml)

## What it is

WYSIWYG `.docx` editing inside the TYPO3 backend, powered by
[eigenpal/docx-editor](https://github.com/eigenpal/docx-editor).

Editors open a Word file from the **File list**, edit it in a full-page backend
view and save straight back to FAL. The editor is a *route* on the file list
(`Edit DOCX` action), not a module in the module menu. Several people editing
the same file see each other's presence, and a save is rejected with HTTP 409
when someone else stored a newer revision.

> **Google Fonts:** the bundled upstream editor *attempts* to load web fonts
> from `fonts.googleapis.com` / `fonts.gstatic.com`. This extension blocks those
> requests in the backend (`typo3-disable-external-fonts.js`) and falls back to
> system fonts plus fonts embedded in the DOCX, so the backend CSP needs no
> Google hosts. See [Security](Documentation/Security/Index.rst).

## Requirements

| | |
| --- | --- |
| TYPO3 | 14.3 LTS (`^14.3.7`) |
| PHP | 8.4 (CI also runs 8.5 as an allowed failure) |
| Node.js | 22+ — only to rebuild frontend assets |

## Install

```bash
composer require webconsulting/docx-editor
vendor/bin/typo3 extension:setup
```

`extension:setup` creates the `tx_docx_editor_session` and
`tx_docx_editor_revision` tables. Pre-built JavaScript is committed, so a normal
install needs no Node.js step.

## Configure

Nothing to configure — no TypoScript, no TSconfig. Access follows FAL: a user
needs read permission on the storage and file to open a document and write
permission to save (otherwise the editor opens read-only). Routes live in
`Configuration/Backend/Routes.php` (the editor) and
`Configuration/Backend/AjaxRoutes.php` (load, save, save-as, presence,
revision).

## Use

1. Open **File** › **Filelist** in the backend.
2. Pick a `.docx` file and choose **Edit DOCX**.
3. Edit, then save from the docheader (back, save, save as, download) or with
   **Ctrl/Cmd+S**.

Save feedback uses the backend Notification API and names the target path
(`Saved to fileadmin / user_upload/report.docx`). The style dropdown is curated
to **Normal + H1–H4**; the same headings are available as toolbar shortcuts.

## Develop

```bash
composer install
npm ci
Build/Scripts/runTests.sh -s ci   # composer, lint, cgl, phpstan, unit, functional, assets
```

Individual suites: `lint`, `cgl`, `phpstan`, `unit`, `functional`, `composer`,
`assets`.

Frontend changes under `Build/Sources/` need `npm run build`; commit the
regenerated `Resources/Public/Vite/` output (CI fails if it drifts). CSS-only
changes in `Resources/Public/Css/Editor*.css` need no Node step — flush caches
and hard-refresh.

**Chunk patches.** Three Vite plugins in `Build/vite/plugins/` rewrite the
minified upstream bundle: `heading4-fallback` (adds Heading 4 to the built-in
style array), `style-dropdown-headings` (curates the dropdown to Normal + H1–H4)
and `popover-align` (opens the mode picker rightward). They match by content
pattern across all `dist/*.mjs` chunks — newer entries use identifier-agnostic
regular expressions, so a minifier rename alone no longer breaks them.
`npm run test:build` is the gate: it asserts every patch still finds its anchor.
When it fails after an upstream bump, **add** a new shape entry rather than
editing the old ones — the re-anchoring guide is in
[Build/Sources/README.md](Build/Sources/README.md) and
[Documentation/Developer/Index.rst](Documentation/Developer/Index.rst).

## Docs

- [Manual](Documentation/Index.rst) — introduction, installation, usage, security
- [Developer guide](Documentation/Developer/Index.rst) — architecture, patches, quality gates
- [Changelog](CHANGELOG.md)

## License

GPL-2.0-or-later
