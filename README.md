# DOCX Editor for TYPO3 (`docx_editor`)

[![CI](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.3-orange)](https://get.typo3.org/version/14)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue)](https://www.php.net/)

WYSIWYG editing of Word (`.docx`) files inside the TYPO3 backend, powered by
[docx-editor.dev](https://www.docx-editor.dev/) (`@docx-editor.dev/core` and
`@docx-editor.dev/vue`, Apache-2.0): open a file from the file list, edit it
full-page, save it straight back to FAL.

## What it is

- **Edit DOCX** action on `.docx` files in the file list; the editor is a backend
  *route*, not a module in the menu (like the core text-file editor).
- The DocHeader of core's own text-file editor: **Close** (asks before
  discarding unsaved changes, with the core dialog), **Save** with *Save and
  close* and *Save as…* (folder browser, then a file name dialog), and
  **Download**; Ctrl/Cmd+S saves. Feedback via backend notifications.
- Word-faithful layout: text is shaped with HarfBuzz and laid out like Word
  (pages, headers and footers, notes, tables, images, fields, rulers,
  navigation pane). Metric-compatible open fonts for Calibri, Cambria, Arial,
  Times New Roman, Courier New and Century Gothic ship with the extension and
  load same-origin, only when a document uses them — no CDN.
- Lossless round trip: the editor saves the package it opened. Content
  controls, bookmarks, custom XML parts, custom properties, tracked changes,
  comments and everything else it does not model are kept; the test suite
  proves it on representative documents.
- Style picker curated to **Normal + Heading 1–4** plus H1–H4 toolbar buttons.
  Word keeps unused headings latent; the editor adds Word's definitions while
  a document is open and keeps only the ones you applied.
- Menus: File (Save, Page setup — no *Open*, which would replace the FAL
  file), Format, Insert, Review (paragraph marks, forms protection).
- The editor chrome, menus and dialogs follow the backend's light and dark mode
  through TYPO3's design tokens; the document page stays white, as in Word.
- Presence ("2 editors online", a core status indicator) and revision-safe
  saves: a save is rejected with HTTP 409 when someone else stored a newer
  revision, and a warning callout offers to reload.
- English and German, editor chrome included (the upstream German catalogue is
  completed by the extension).

Not included: tracked-change review (suggesting, accept/reject, markup
views), comment threads, real-time collaboration and PDF export — upstream
ships them in the commercial `@docx-editor.dev/pro`, which this extension does
not use. Documents that contain tracked changes or comments keep them on save.

## Requirements

| | |
| --- | --- |
| TYPO3 | 14.3 LTS (`typo3/cms-core ^14.3.7`) |
| PHP | 8.4 or 8.5 (both gate CI) |
| Browser | current Chrome, Edge, Firefox or Safari (WebAssembly) |
| Node.js | 22.12+ — only to rebuild the frontend bundle |

## Install

```bash
composer require webconsulting/docx-editor:^2.0
vendor/bin/typo3 extension:setup
```

`extension:setup` creates `tx_docx_editor_session` and `tx_docx_editor_revision`.
The Vite bundle is committed; a normal install needs no Node.js.

## Configure

Nothing to configure — no TypoScript, no TSconfig. Access follows FAL: read
permission opens a document (read-only), write permission enables saving.
Routes: `Configuration/Backend/Routes.php` (editor) and
`Configuration/Backend/AjaxRoutes.php` (load, save, save-as, presence, revision).

**Content-Security-Policy:** on the editor route only, the extension adds
`script-src 'wasm-unsafe-eval'` (the HarfBuzz text shaper is WebAssembly; this
allows compiling WebAssembly, not JavaScript `eval`) and `img-src blob:` (the
engine paints document images from `blob:` URLs). Every other backend route
keeps the core policy. See `AllowEditorEngineInContentSecurityPolicy`.

## Use

1. **Media › Filelist**, pick a `.docx`, choose **Edit DOCX**.
2. Edit; save with **Save** or **Ctrl/Cmd+S**; the Save dropdown offers
   **Save and close** and **Save as…** (target folder, then file name).
3. **Close** returns to the folder; with unsaved changes it asks first.
4. If another editor saved meanwhile, a warning callout offers **Reload document**.

## Integrate

The bundle (`@webconsulting/docx-editor/editor.js` in the backend import map)
defines two custom elements and exports them.

### `<webcon-docx-editor>` — the editor with a byte-level API

```js
import '@webconsulting/docx-editor/editor.js';

const editor = document.createElement('webcon-docx-editor');
editor.setAttribute('locale', 'de');                  // chrome language
editor.setAttribute('content-controls', 'show');      // optional, see below
editor.style.height = '70vh';                         // give it a height
container.append(editor);

await editor.load(bytes);                             // ArrayBuffer | Uint8Array | Blob
editor.addEventListener('docx-editor:change', (e) => console.log(e.detail.dirty));
editor.addEventListener('docx-editor:save-request', async () => {
  const revision = editor.revision;
  const docx = await editor.serialize();              // Uint8Array
  await store(docx);
  editor.markClean(revision);
});
```

| API | |
| --- | --- |
| `locale` attribute | `de` / `en` |
| `readonly` attribute | opens for viewing only |
| `content-controls="show"` | draws every content control's boundary and tag, and removes the *Remove* actions (toolbar and control popup), so controls that map to records cannot be deleted by accident |
| `labels` property | names for Normal/Heading 1–4 (defaults: English) |
| `load(source)` | opens a document; resolves once it is shown |
| `serialize()` | the document as DOCX bytes: the **opened package, re-serialized** by the engine's package serializer — never a new document; unmodeled markup, `w:sdt`, bookmarks and custom XML parts survive |
| `revision`, `dirty`, `markClean(revision)` | edit tracking |
| `editor` | the `@docx-editor.dev/core` editor instance (commands, queries) |
| events | `docx-editor:ready` {editor}, `docx-editor:change` {dirty, revision}, `docx-editor:save-request`, `docx-editor:error` {message}, `docx-editor:font-error` {error} |

### `<typo3-docx-editor>` — the backend module element

Loads and saves through the FAL AJAX routes and adds presence and the
newer-version warning. Attributes: `file-identifier`, `file-name`, `revision`,
`can-write`, `editor-locale`, `content-controls`, and two endpoint overrides:

- `load-url` — GET, answers `{ok, data (base64 DOCX), revision}`
  (default: route `docx_editor_document_load`, `file` query parameter added);
- `save-url` — POST, receives `{file, revision, data (base64 DOCX)}` and
  answers `{ok, revision}` (default: route `docx_editor_document_save`).

Point `save-url` elsewhere (for example a page-sync endpoint) and the editor
saves there with the same payload. `save()`, `saveAsToFolder()`, `dirty` and
`editorElement` (the inner `<webcon-docx-editor>`) are public.

## Develop

```bash
composer install && npm ci
composer ci        # validate, lint, cgl, phpstan, unit, functional
composer assets    # npm ci, test:build, build, git diff --exit-code
```

Frontend sources live in `Build/Sources/`: the two custom elements, the Vue
editor composition (`editor/*.vue`, compiled at build time; the bundle carries
Vue's runtime-only build) and the curated-styles logic. `npm run test:build`
runs the node tests in `Build/Tests/`: round-trip fidelity on the fixtures in
`Build/Tests/Fixtures/` (headless, in happy-dom), the German catalogue overlay
and the label keys. After changing `Build/Sources/` run `npm run build` and
commit `Resources/Public/Vite/`; CI fails on drift. CSS in
`Resources/Public/Css/` needs no build.

## Docs

- [Manual](Documentation/Index.rst): introduction, installation, usage, configuration and security
- [Developer guide](Documentation/Developer/Index.rst): architecture, integration API, build, tests
- [Changelog](CHANGELOG.md)

## License

GPL-2.0-or-later. The bundled editor packages are Apache-2.0, the fonts SIL
OFL 1.1 / GUST; their licence texts ship in `Resources/Public/Vite/licenses/`.
