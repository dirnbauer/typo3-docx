# DOCX Editor for TYPO3 (`docx_editor`)

[![CI](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.3-orange)](https://get.typo3.org/version/14)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue)](https://www.php.net/)

WYSIWYG editing of Word (`.docx`) files inside the TYPO3 backend, powered by
[docx-editor.dev](https://www.docx-editor.dev/) (`@docx-editor.dev/core` and
`@docx-editor.dev/vue`, Apache-2.0): open a file from the file list, edit it
full-page, save it straight back to FAL.

**Edit pages in Word:** a page and its content elements become one Word
document — edit it in the backend or in Word, LibreOffice or any word
processor, and import it back after reviewing every change. A Word document
can become new pages, split into the content elements that fit it.

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
- **Print** (DocHeader, File › Print, Ctrl/Cmd+P): one sheet per page at the
  document's own paper size — landscape sections print landscape; the
  browser's *Save as PDF* makes a PDF.
- Menus: File (Save, Print, Page setup — no *Open*, which would replace the FAL
  file), Format, Insert, Review (paragraph marks, forms protection).
- The editor chrome, menus and dialogs follow the backend's light and dark mode
  through TYPO3's design tokens; the document page stays white, as in Word.
- Presence ("2 editors online", a core status indicator) and revision-safe
  saves: a save is rejected with HTTP 409 when someone else stored a newer
  revision, and a warning callout offers to reload.
- English and German, editor chrome included (the upstream German catalogue is
  completed by the extension).

Not included: tracked-change review (suggesting, accept/reject, markup
views), comment threads and real-time collaboration — upstream ships them in
the commercial `@docx-editor.dev/pro`, which this extension does not use. Documents that contain tracked changes or comments keep them on save.

## Requirements

| | |
| --- | --- |
| TYPO3 | 14.3 LTS (`typo3/cms-core ^14.3.7`) |
| PHP | 8.4 or 8.5 (both gate CI) |
| Browser | current Chrome, Edge, Firefox or Safari (WebAssembly) |
| Node.js | 22.12+ — only to rebuild the frontend bundle |
| PHP extensions | `dom`, `libxml`, `zip` |
| Optional | [`webconsulting/webcon-jev`](https://github.com/dirnbauer/typo3-webcon-jev) `^0.2.1` — Jev chooses between content types that fit a Word part equally well |

## Install

```bash
composer require webconsulting/docx-editor:^2.2
vendor/bin/typo3 extension:setup
```

`extension:setup` creates `tx_docx_editor_session` and `tx_docx_editor_revision`.
The Vite bundle is committed; a normal install needs no Node.js.

## Configure

The file editor needs no configuration — no TypoScript, no TSconfig. Access
follows FAL: read permission opens a document (read-only), write permission
enables saving. The page round trip has a few extension settings (see below).
Routes: `Configuration/Backend/Routes.php` (editor, page editor, import as
subpages, page download) and `Configuration/Backend/AjaxRoutes.php` (load,
save, save-as, presence, revision; page load, preview, apply, discard).

**Content-Security-Policy:** on the editor routes only (`docx_editor` and the
page editor `docx_editor_page`), the extension adds
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

## Edit pages in Word

The Page module's DocHeader gets a **Word** menu (and the page tree's context
menu the same entries), shown only where the editor may use them:

- **Edit in Word** — the page in the embedded editor. The page title is the
  first line; every content element is a Word *content control* named after
  its type, every field a control inside it (collection items of Content
  Blocks elements included). Edit inside the frames, write new sections
  between elements, delete or move elements. **Review and save** (or
  Ctrl/Cmd+S) shows what the import would change and writes it on **Import**.
- **Download as Word document** / **Upload Word document…** — the same round
  trip through Word, LibreOffice or any other word processor.
- **Import Word document as subpages…** — one page (titled by the first
  Heading 1), a page per Heading 1 or a page per page break; new pages start
  hidden.

The review lists every element — new (with the proposed content type and the
alternatives), changed, conflicting (changed in Word *and* in TYPO3 since the
export: pick a side per field), removed in Word (deleted only when you tick
it), translated or read-only. Everything is written through the DataHandler as
the current user, in the current workspace and language. If TYPO3 changed
between review and import, nothing is written.

New content is split at headings, page breaks and horizontal lines; each part
becomes the allowed content type it fills best (heading → header, text →
body, pictures → image field, question/answer pairs or steps → a collection,
quote → quote field…). The allowed types are those the New Content Element
wizard offers for the column. Where types fit equally well and
`webcon_jev` is installed, **Jev** chooses among them; below its confidence
threshold the structural fit stays and the element is marked for review.

```bash
vendor/bin/typo3 docx-editor:page:export 42 --out=/tmp/              # page 42 as .docx
vendor/bin/typo3 docx-editor:page:import /tmp/x.docx --pid=42        # dry run: prints the plan
vendor/bin/typo3 docx-editor:page:import /tmp/x.docx --pid=42 --apply --confirm-deletions
vendor/bin/typo3 docx-editor:page:import book.docx --parent=7 --split=h1 --apply
```

Pictures travel as copies scaled by TYPO3's image processing to the size Word
shows them (150 ppi, at most 2000 px on the long edge, in the file's format),
so a page full of photos fits the upload limit. The manifest records which
copy stands for which file reference: an unchanged picture comes back as the
same reference and file, only a picture replaced in Word becomes a new file.
Link fields show what they point to (page title and path, file, record,
e-mail address, URL) and are never written back.

Settings (`pageSync.*` in the extension configuration): picture folder
(default `1:/user_upload/word/{page}/`, pictures deduplicated by SHA-1),
new pages hidden, upload limit (25 MB), picture resolution (150 ppi) and
longest picture edge (2000 px), a Word template (`.dotx`) for exported
documents, excluded content types (`html`), and Jev (on/off, confidence
threshold 0.6, candidates, cache lifetime).

What Word cannot carry: fonts, colours, sizes and alignment are not imported;
CSS classes and inline styles of a rich text field are dropped when that
field is changed in Word (the review says so); rich text with embedded media
or iframes, link fields, plugins and non-picture files are read-only; two
quotes in a row become one; text boxes become paragraphs; charts, SmartArt and
EMF/WMF/TIFF pictures, headers, footers, footnotes and comments are not
imported. Elements in containers or in columns the backend layout does not
show are not part of the document. If a word processor removes the content
controls, elements are recognised by their bookmarks, then by their text.

Details: [Edit pages in Word](Documentation/PageSync/Index.rst).

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
| `print()` | the browser's print dialog with one sheet per page at the document's paper size (also File › Print and Ctrl/Cmd+P) |
| `preparePrint()` | readies the pages for print media without the dialog and resolves to a cleanup function — for headless PDF rendering |
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

- [Manual](Documentation/Index.rst): introduction, installation, usage, editing pages in Word, configuration and security
- [Developer guide](Documentation/Developer/Index.rst): architecture, integration API, build, tests
- [Changelog](CHANGELOG.md)

## License

GPL-2.0-or-later. The bundled editor packages are Apache-2.0, the fonts SIL
OFL 1.1 / GUST; their licence texts ship in `Resources/Public/Vite/licenses/`.
