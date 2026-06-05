..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Open a document
===============

#. Sign in to the TYPO3 backend.
#. Open :guilabel:`Media`.
#. Browse to a folder that contains a `.docx` file.
#. Click the primary action :guilabel:`Edit DOCX`.

The editor opens in the Media submodule. The TYPO3 **docheader** shows FAL
breadcrumbs and action buttons (back to the media folder, save, save as).

Saving
======

- Click :guilabel:`Save` in the docheader, or press :kbd:`Ctrl+S` /
  :kbd:`Cmd+S`.
- Use :guilabel:`Save as` to store a copy in another FAL folder (folder
  browser dialog).
- Success and error feedback appears as TYPO3 backend notifications.

The eigenpal formatting toolbar uses TYPO3 design tokens and wraps to multiple
lines on narrow backend widths (no horizontal scrollbar). H1–H4 quick-style
buttons appear at the start of the formatting bar when you have write access.

Permissions
===========

Users need read permission to open a file and write permission on the storage
to save. Without write permission the editor opens in viewing mode.

Collaboration
=============

When several users edit the same file:

- The module header shows how many editors are currently online.
- Saving increments a server-side revision counter.
- If someone else saved while you were editing, the next save is rejected and
  a TYPO3 warning banner offers :guilabel:`Reload`.

Reload to load the newest FAL contents before continuing.
