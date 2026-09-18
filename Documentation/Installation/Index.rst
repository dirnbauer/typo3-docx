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

Upgrading from 1.3
==================

Run ``vendor/bin/typo3 extension:setup`` (or the database compare) once: the
presence table no longer has a ``deleted`` column; stale sessions are removed
instead of soft-deleted.
