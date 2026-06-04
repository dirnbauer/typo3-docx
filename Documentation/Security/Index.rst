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

Recommendations
===============

- Limit write access to FAL storages that contain sensitive documents.
- Use workspaces and publishing workflows as usual; the extension writes the
  live FAL file.
