# DOCX Editor for TYPO3 (`docx_editor`)

[![CI](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-docx/actions/workflows/ci.yml)

WYSIWYG `.docx` editing in the TYPO3 **14.3+** Media module, powered by [eigenpal/docx-editor](https://github.com/eigenpal/docx-editor).

Editors open Word files from the media file list, edit in the backend, and save back to FAL. Multiple users see who is online; saves detect conflicts when someone else stored a newer revision.

## Requirements

| | |
| --- | --- |
| TYPO3 | 14.3 LTS (`^14.3`) |
| PHP | 8.2 – 8.4 (CI tests 8.3 and 8.4) |
| Node.js | 20+ only when rebuilding frontend assets |

## Quick start

```bash
composer require webconsulting/docx-editor
vendor/bin/typo3 extension:setup
```

Pre-built JavaScript is included — no Node.js step is required for a normal install.

1. Open **Media** in the backend.
2. Choose a `.docx` file → **Edit DOCX**.
3. Edit and save from the editor toolbar.

## Documentation

- [Full TYPO3 manual](Documentation/Index.rst) (Introduction, installation, usage, security)
- [Changelog](CHANGELOG.md)

## Development

```bash
composer install
npm ci && npm run build   # only when changing Build/Sources/
Build/Scripts/runTests.sh -s ci
```

## Collaboration

**Presence** and **revision-aware saves** are included. Full real-time OT/CRDT (Yjs, etc.) is supported by the upstream editor but not wired in v1.0.0; see [Developer](Documentation/Developer/Index.rst) for extension points.

## License

GPL-2.0-or-later
