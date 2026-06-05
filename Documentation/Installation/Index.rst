..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Composer
========

..  code-block:: bash
    :caption: Install the extension

    composer require webconsulting/docx-editor

The repository includes pre-built assets under
:file:`Resources/Public/Vite/`. A normal Composer install does **not** require
Node.js.

TYPO3 setup
===========

..  code-block:: bash
    :caption: Activate and update the database schema

    vendor/bin/typo3 extension:setup

This creates the collaboration tables
:sql:`tx_docx_editor_session` and :sql:`tx_docx_editor_revision`.

..  _installation-rebuild:

Rebuild frontend assets
=======================

Rebuild only when you change :file:`Build/Sources/` or upgrade the npm
packages:

..  code-block:: bash
    :caption: Rebuild the Vite bundle

    npm ci
    npm run test:build
    npm run build

``test:build`` verifies the Heading 4 Vite patch still matches the installed
``@eigenpal/docx-editor-react`` version.

Commit the generated files in :file:`Resources/Public/Vite/` before tagging a
release.

Theme-only changes in :file:`Resources/Public/Css/Editor*.css` do not require
a Node.js rebuild — flush caches and hard-refresh the backend instead.
