..  include:: /Includes.rst.txt

..  _security:

========
Security
========

Access control
==============

- All document and collaboration endpoints run in the authenticated backend
  context.
- FAL storage and file permissions are checked before read or write.
- Saves with an outdated revision number return HTTP 409 instead of
  overwriting a newer save.

Data handling
=============

- Document binaries are transferred as base64 in JSON over HTTPS (same origin).
- Collaboration sessions store backend user id, display name, and heartbeat
  timestamps only — not document content.

Content Security Policy and external fonts
==========================================

The bundled `eigenpal/docx-editor` runtime **attempts to load web fonts from
Google** (`fonts.googleapis.com`, `fonts.gstatic.com`) for common Office
typefaces.

This extension ships ``typo3-disable-external-fonts.js``, which blocks those
stylesheet injections in the TYPO3 backend so the default CSP does not need
Google hosts whitelisted. Fallbacks are system UI fonts and fonts embedded in
the opened DOCX.

If you customize the frontend bundle or remove the guard, font requests may
reach Google again. Review privacy and CSP policy before doing so.

Recommendations
===============

- Limit write access to FAL storages that contain sensitive documents.
- Use workspaces and publishing workflows as usual; the extension writes the
  live FAL file.
- Keep the Google Fonts guard enabled unless you explicitly allow external
  font hosts in backend CSP.
