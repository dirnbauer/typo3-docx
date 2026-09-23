..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  code-block:: bash
    :caption: Install and create the tables

    composer require webconsulting/docx-editor
    vendor/bin/typo3 extension:setup

``extension:setup`` creates :sql:`tx_docx_editor_session` (presence) and
:sql:`tx_docx_editor_revision` (save counter). Neither table stores document
content.

The Vite bundle in :file:`Resources/Public/Vite/` is committed, so a normal
install needs no Node.js. Rebuilding is only necessary after changing
:file:`Build/Sources/` or bumping npm packages, see :ref:`developer`.

Upgrading from 1.x
==================

2.0 replaces the editor engine (``@eigenpal/docx-editor`` 1.9, React) with
docx-editor.dev 2 (Vue). Nothing changes on the PHP side — routes, tables and
permissions are the same:

..  code-block:: bash

    composer require webconsulting/docx-editor:^2.0

Things to know:

-   The editor needs WebAssembly; the extension allows it on the editor route
    only (see :ref:`security`).
-   :file:`Resources/Public/Vite/` now holds the module plus lazy chunks, the
    fonts and the WebAssembly module (about 11 MB; the browser loads the fonts
    a document uses, not all of them).
-   Tracked-change review and comment threads of the 1.x editor are not part of
    the open-source 2.x engine (see :ref:`introduction`).

Upgrading from 1.3
==================

Run ``vendor/bin/typo3 extension:setup`` (or the database compare) once: the
presence table no longer has a ``deleted`` column; stale sessions are removed
instead of soft-deleted.
