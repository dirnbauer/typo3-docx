# Changelog

All notable changes to this project are documented in this file.

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
