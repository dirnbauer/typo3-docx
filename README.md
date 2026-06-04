# TYPO3 DOCX Editor (`docx_editor`)

TYPO3 **14.3+ only** extension that adds WYSIWYG `.docx` editing to the **Media** module, powered by [eigenpal/docx-editor](https://github.com/eigenpal/docx-editor).

Editors open `.docx` files from the media file list, edit in the backend, and save back to FAL. Multiple backend users can work on the same file with **presence** (who is online) and **revision-aware saves** (conflict detection when someone else saved a newer copy).

## Requirements

- TYPO3 14.3 LTS (`typo3/cms-core` ^14.3)
- PHP 8.3+
- Node.js 20+ (to build frontend assets)

## Installation

```bash
composer require webconsulting/docx-editor
```

Build the bundled editor (Lit glue + React adapter for docx-editor):

```bash
cd vendor/webconsulting/docx-editor
npm ci
npm run build
```

Activate the extension and run database updates so collaboration tables are created:

```bash
vendor/bin/typo3 extension:setup
```

## Usage

1. Go to **Media** in the TYPO3 backend.
2. Select a `.docx` file.
3. Use the primary action **Edit DOCX** (or open the submodule route with a `file` query parameter).
4. Edit the document and save from the editor toolbar (writes back to the FAL file).
5. When another user saves, you get a reload banner; concurrent editors are listed in the toolbar.

## Architecture

| Layer | Responsibility |
| --- | --- |
| PHP (TYPO3 v14) | FAL read/write, permissions, AJAX API, collaboration sessions, revision log |
| Fluid 5 | Backend module markup, labels via XLIFF 2 + ICU |
| Lit | `typo3-docx-editor` custom element — TYPO3-facing glue only |
| React + `@eigenpal/docx-editor-react` | WYSIWYG editor UI (bundled, not loaded separately by TYPO3) |

Collaboration today: **presence** + **revision polling** + optimistic locking on save. The editor library supports Yjs/Liveblocks-style backends for full real-time merging; this extension exposes stable revision endpoints so you can plug a dedicated sync service later.

## Development

```bash
composer install
npm ci
npm run build
Build/Scripts/runTests.sh -s ci
```

### Test suites

```bash
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s assets
```

## License

GPL-2.0-or-later
